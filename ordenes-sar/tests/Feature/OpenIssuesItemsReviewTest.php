<?php

namespace Tests\Feature;

use App\Jobs\SendHheeMailJob;
use App\Mail\OpenIssueMail;
use App\Models\Departamento;
use App\Models\Notificacion;
use App\Models\OpenIssue;
use App\Models\OpenIssueActualizacion;
use App\Models\OpenIssueItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Casos de las correcciones de code review sobre "Items dentro de un Open
 * Issue" (§10 de SPEC-open-issues.md), que ni OpenIssuesItemsTest.php ni
 * OpenIssuesItemsBordesTest.php cubrían:
 *
 *  #1/#5. Responsable nuevo no involucrado recibe UN solo aviso por
 *         asignación (antes: "te involucraron" + "te asignó el item").
 *         Varios items al mismo responsable en un solo POST -> un aviso
 *         agrupado con el total, no uno por item.
 *  #4.    PUT con titulo vacío -> 422, sin fila 'item_editado' fantasma.
 *  #6.    Usuario sin alcance recibe 403 (no 404) tanto con un item propio
 *         del issue como con un item de OTRO issue.
 *  #9.    catalogos() expone max_items.
 *
 * Mismo criterio de DB (sqlite :memory:) que el resto del módulo.
 */
class OpenIssuesItemsReviewTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers de armado (mismo patrón que OpenIssuesItemsTest/OpenIssuesItemsBordesTest)
    // =========================================================================

    private function departamento(string $nombre = 'Producción'): Departamento
    {
        return Departamento::create(['nombre' => $nombre]);
    }

    private function usuario(Departamento $departamento, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'departamento_id' => $departamento->id,
            'rol' => 'team_member',
            'turno' => 'Turno Mañana',
        ], $overrides));
    }

    private function payloadIssue(array $overrides = []): array
    {
        return array_merge([
            'titulo' => 'Funda 47A con hilo suelto en costura lateral',
            'descripcion' => 'Se detectó en control de calidad sobre unidades del turno noche.',
        ], $overrides);
    }

    private function crearIssueVia(User $actor, array $overrides = []): OpenIssue
    {
        Passport::actingAs($actor);

        $resp = $this->postJson('/api/open-issues', $this->payloadIssue($overrides));
        $resp->assertStatus(201);

        return OpenIssue::findOrFail($resp->json('id'));
    }

    private function crearItemVia(OpenIssue $issue, User $actor, array $overrides = []): OpenIssueItem
    {
        Passport::actingAs($actor);

        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [array_merge(['titulo' => 'Punto a mejorar'], $overrides)],
        ]);
        $resp->assertStatus(201);

        return OpenIssueItem::where('issue_id', $issue->id)->orderBy('id', 'desc')->firstOrFail();
    }

    private function notificacionesOI(User $destinatario, int $issueId, string $fragmentoMensaje): int
    {
        return Notificacion::where('usuario_creador_id', $destinatario->id)
            ->where('tipo', 'open_issue')
            ->where('open_issue_id', $issueId)
            ->where('mensaje', 'like', "%{$fragmentoMensaje}%")
            ->count();
    }

    private function jobsMailOpenIssue(string $evento, ?string $email = null): \Illuminate\Support\Collection
    {
        return Queue::pushed(SendHheeMailJob::class, function (SendHheeMailJob $job) use ($evento, $email) {
            $mailable = $job->mailable();

            if (!$mailable instanceof OpenIssueMail || $mailable->evento !== $evento) {
                return false;
            }

            if ($email !== null && $job->destinatarioEmail() !== $email) {
                return false;
            }

            return true;
        });
    }

    // =========================================================================
    // (a) Responsable nuevo no involucrado: UNA sola campana y UN solo mail
    // por asignación de 1 item (antes eran 2 de cada uno).
    // =========================================================================

    public function test_responsable_nuevo_no_involucrado_recibe_una_sola_campana_y_un_solo_mail_por_un_item(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $responsable = $this->usuario($this->departamento('Otro'), ['name' => 'Responsable Nuevo']);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Queue::fake();

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [['titulo' => 'Punto con responsable nuevo', 'responsable_id' => $responsable->id]],
        ]);
        $resp->assertStatus(201);

        // Campana de la ASIGNACIÓN: exactamente 1 (antes de la corrección eran 2:
        // "Te involucraron..." de asegurarResponsableInvolucrado() + "te asignó el
        // item..." del caller). La campana aparte de "agregó 1 item(s)..." (evento
        // general de alta, notificarItemsAgregados) es un aviso distinto, no forma
        // parte de este fix y también le llega (ver §10.5): no se cuenta acá.
        $this->assertSame(1, $this->notificacionesOI($responsable, $issue->id, 'te asignó el item'));
        $this->assertSame(0, $this->notificacionesOI($responsable, $issue->id, 'Te involucraron'));

        // Mail: exactamente 1 job encolado (evento 'involucrado') para el responsable.
        $this->assertCount(1, $this->jobsMailOpenIssue('involucrado', $responsable->email));
    }

    // =========================================================================
    // (b) 3 items al mismo responsable en un solo POST -> 1 campana + 1 mail
    // agrupados con el texto de N items.
    // =========================================================================

    public function test_tres_items_al_mismo_responsable_en_un_solo_post_agrupan_un_aviso(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $responsable = $this->usuario($this->departamento('Otro'), ['name' => 'Responsable Lote']);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Queue::fake();

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [
                ['titulo' => 'Item lote 1', 'responsable_id' => $responsable->id],
                ['titulo' => 'Item lote 2', 'responsable_id' => $responsable->id],
                ['titulo' => 'Item lote 3', 'responsable_id' => $responsable->id],
            ],
        ]);
        $resp->assertStatus(201);

        // Campana de la ASIGNACIÓN: 1 sola fila, con el texto agrupado de 3 items
        // (no 3 filas, una por item).
        $this->assertSame(
            1,
            $this->notificacionesOI($responsable, $issue->id, "te asignó 3 items del Open Issue #{$issue->id}")
        );
        $this->assertSame(0, $this->notificacionesOI($responsable, $issue->id, 'te asignó el item'));

        // Mail: 1 solo job encolado.
        $this->assertCount(1, $this->jobsMailOpenIssue('involucrado', $responsable->email));

        // Solo 1 fila 'involucrado_agregado' (asegurarResponsableInvolucrado se llamó
        // una vez por responsable, no una vez por item).
        $this->assertSame(
            1,
            OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'involucrado_agregado')->count()
        );
    }

    // =========================================================================
    // (c) PUT con titulo: "" -> 422 y no crea fila item_editado.
    // =========================================================================

    public function test_editar_item_con_titulo_vacio_da_422_y_no_crea_fila_item_editado(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador, ['titulo' => 'Título original']);

        Passport::actingAs($creador);
        $resp = $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", ['titulo' => '']);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['titulo']);

        $item->refresh();
        $this->assertSame('Título original', $item->titulo);

        $this->assertSame(
            0,
            OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'item_editado')->count()
        );
    }

    // =========================================================================
    // (d) Usuario sin alcance recibe 403 (no 404), tanto con un item del
    // issue como con un item de otro issue.
    // =========================================================================

    public function test_usuario_sin_alcance_recibe_403_con_item_propio_y_con_item_de_otro_issue(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $ajeno = $this->usuario($this->departamento('Depósito'));

        $issue1 = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item1 = $this->crearItemVia($issue1, $creador);

        $issue2 = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item2 = $this->crearItemVia($issue2, $creador);

        Passport::actingAs($ajeno);

        // Item que SÍ pertenece a issue1: 403 (no autorizado), nunca 404.
        $this->putJson("/api/open-issues/{$issue1->id}/items/{$item1->id}", ['titulo' => 'No debería'])
            ->assertStatus(403);
        $this->postJson("/api/open-issues/{$issue1->id}/items/{$item1->id}/estado", ['estado' => 'hecho'])
            ->assertStatus(403);

        // Item de OTRO issue (issue2): también 403, no 404 -- el usuario sin
        // alcance no puede distinguir por el código de estado si el item
        // existe en otro issue o no existe en absoluto.
        $this->putJson("/api/open-issues/{$issue1->id}/items/{$item2->id}", ['titulo' => 'No debería'])
            ->assertStatus(403);
        $this->postJson("/api/open-issues/{$issue1->id}/items/{$item2->id}/estado", ['estado' => 'hecho'])
            ->assertStatus(403);
    }

    // =========================================================================
    // (e) catalogos.max_items = 50 (default de config('open_issues.max_items_por_issue')).
    // =========================================================================

    public function test_catalogos_incluye_max_items(): void
    {
        $creador = $this->usuario($this->departamento('IT'));

        Passport::actingAs($creador);
        $resp = $this->getJson('/api/open-issues/catalogos');
        $resp->assertStatus(200);
        $resp->assertJsonPath('max_items', 50);
    }

    public function test_catalogos_max_items_refleja_el_config_no_hardcodeado(): void
    {
        config(['open_issues.max_items_por_issue' => 7]);

        $creador = $this->usuario($this->departamento('IT'));

        Passport::actingAs($creador);
        $resp = $this->getJson('/api/open-issues/catalogos');
        $resp->assertStatus(200);
        $resp->assertJsonPath('max_items', 7);
    }
}
