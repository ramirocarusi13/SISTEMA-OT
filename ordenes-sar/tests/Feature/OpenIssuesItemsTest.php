<?php

namespace Tests\Feature;

use App\Jobs\SendHheeMailJob;
use App\Mail\OpenIssueMail;
use App\Models\Departamento;
use App\Models\Notificacion;
use App\Models\OpenIssue;
use App\Models\OpenIssueActualizacion;
use App\Models\OpenIssueInvolucrado;
use App\Models\OpenIssueItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Test de Feature del anexo "Items dentro de un Open Issue" (§10 de
 * SPEC-open-issues.md): alta (con tope de items, orden correlativo),
 * agregado posterior, cambio de estado (con auto en_progreso del issue y sin
 * auto-cierre), progreso agregado, comentarios ligados a un item,
 * responsable no involucrado, alcance/cierre y notificaciones (campana +
 * mail 'item_estado'). No modifica los tests existentes de
 * OpenIssuesCircuitoTest.php/OpenIssuesMailTest.php.
 *
 * Entorno de DB: sqlite :memory: (mismo criterio que el resto del módulo).
 */
class OpenIssuesItemsTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers de armado (mismo patrón que OpenIssuesCircuitoTest/OpenIssuesMailTest)
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

    /**
     * Agrega un item vía HTTP (POST /{id}/items) actuando como $actor y
     * devuelve el modelo ya persistido (el último creado para este issue).
     */
    private function crearItemVia(OpenIssue $issue, User $actor, array $overrides = []): OpenIssueItem
    {
        Passport::actingAs($actor);

        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [array_merge(['titulo' => 'Punto a mejorar'], $overrides)],
        ]);
        $resp->assertStatus(201);

        return OpenIssueItem::where('issue_id', $issue->id)->orderBy('id', 'desc')->firstOrFail();
    }

    private function tieneNotificacionOI(User $destinatario, int $issueId, string $fragmentoMensaje): bool
    {
        return Notificacion::where('usuario_creador_id', $destinatario->id)
            ->where('tipo', 'open_issue')
            ->where('open_issue_id', $issueId)
            ->where('mensaje', 'like', "%{$fragmentoMensaje}%")
            ->exists();
    }

    /**
     * Jobs de mail de open issue encolados con evento $evento (copiado de
     * OpenIssuesMailTest::jobsMailOpenIssue()).
     */
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
    // 1: Alta de issue con items (orden correlativo, tope -> 422)
    // =========================================================================

    public function test_alta_de_issue_con_items_asigna_orden_correlativo_y_registra_item_agregado(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        Passport::actingAs($creador);
        $resp = $this->postJson('/api/open-issues', $this->payloadIssue([
            'departamento_destino_id' => $deptoDestino->id,
            'items' => [
                ['titulo' => 'Revisar costura lateral'],
                ['titulo' => 'Revisar hilo suelto'],
                ['titulo' => 'Revisar etiqueta'],
            ],
        ]));

        $resp->assertStatus(201);
        $resp->assertJsonCount(3, 'items');
        $this->assertSame([1, 2, 3], collect($resp->json('items'))->pluck('orden')->all());
        $this->assertSame(
            ['Revisar costura lateral', 'Revisar hilo suelto', 'Revisar etiqueta'],
            collect($resp->json('items'))->pluck('titulo')->all()
        );

        $issueId = $resp->json('id');
        $fila = OpenIssueActualizacion::where('issue_id', $issueId)->where('tipo', 'item_agregado')->first();
        $this->assertNotNull($fila);
        $this->assertStringContainsString('3 items', $fila->texto);

        // progreso: 3 pendientes, ninguno hecho, no está completo.
        $this->assertSame(3, $resp->json('progreso.total'));
        $this->assertSame(0, $resp->json('progreso.hechos'));
        $this->assertFalse($resp->json('progreso.completo'));
    }

    public function test_alta_de_issue_con_mas_items_que_el_tope_da_422(): void
    {
        config(['open_issues.max_items_por_issue' => 2]);

        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        Passport::actingAs($creador);
        $resp = $this->postJson('/api/open-issues', $this->payloadIssue([
            'departamento_destino_id' => $deptoDestino->id,
            'items' => [
                ['titulo' => 'Item 1'],
                ['titulo' => 'Item 2'],
                ['titulo' => 'Item 3'],
            ],
        ]));

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['items']);
    }

    // =========================================================================
    // 2: Agregar items después del alta
    // =========================================================================

    public function test_agregar_items_despues_del_alta_devuelve_201_con_el_detalle_completo(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [
                ['titulo' => 'Nuevo punto 1'],
                ['titulo' => 'Nuevo punto 2'],
            ],
        ]);

        $resp->assertStatus(201);
        $resp->assertJsonCount(2, 'items');
        $this->assertSame([1, 2], collect($resp->json('items'))->pluck('orden')->all());
        $this->assertSame(2, OpenIssueItem::where('issue_id', $issue->id)->count());
    }

    public function test_agregar_items_que_supera_el_tope_acumulado_da_422_y_no_inserta_nada(): void
    {
        config(['open_issues.max_items_por_issue' => 3]);

        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'items' => [['titulo' => 'Item 1'], ['titulo' => 'Item 2']],
        ]);
        $this->assertSame(2, OpenIssueItem::where('issue_id', $issue->id)->count());

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [['titulo' => 'Item 3'], ['titulo' => 'Item 4']],
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['items']);
        // Nada se insertó: el tope se valida ANTES de insertar (mismo criterio que involucrados).
        $this->assertSame(2, OpenIssueItem::where('issue_id', $issue->id)->count());
    }

    // =========================================================================
    // 3-4: Cambio de estado de item (timeline, resuelto_por/fecha_resuelto,
    // auto en_progreso del issue, NUNCA auto-cierre)
    // =========================================================================

    public function test_cambiar_estado_de_item_registra_item_estado_y_setea_resuelto_por_y_fecha(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador);

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", [
            'estado' => 'hecho',
            'texto' => 'Ya se corrigió',
        ]);
        $resp->assertStatus(200);

        $itemActualizado = collect($resp->json('items'))->firstWhere('id', $item->id);
        $this->assertSame('hecho', $itemActualizado['estado']);
        $this->assertNotNull($itemActualizado['resuelto_por']);
        $this->assertSame($creador->id, $itemActualizado['resuelto_por']['id']);
        $this->assertNotNull($itemActualizado['fecha_resuelto']);

        $fila = OpenIssueActualizacion::where('issue_id', $issue->id)
            ->where('tipo', 'item_estado')
            ->where('item_id', $item->id)
            ->first();
        $this->assertNotNull($fila);
        $this->assertSame('pendiente', $fila->estado_anterior);
        $this->assertSame('hecho', $fila->estado_nuevo);
        $this->assertSame('Ya se corrigió', $fila->texto);

        $item->refresh();
        $this->assertSame($creador->id, $item->resuelto_por_id);
        $this->assertNotNull($item->fecha_resuelto);

        // Volver a pendiente limpia resuelto_por_id/fecha_resuelto.
        $volver = $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", [
            'estado' => 'pendiente',
        ]);
        $volver->assertStatus(200);

        $item->refresh();
        $this->assertNull($item->resuelto_por_id);
        $this->assertNull($item->fecha_resuelto);
    }

    public function test_cambiar_al_mismo_estado_da_422(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador);

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", [
            'estado' => 'pendiente',
        ]);
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['estado']);
    }

    public function test_item_en_progreso_o_hecho_pasa_el_issue_abierto_a_en_progreso_sin_cerrarlo_nunca(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador);

        $this->assertSame('abierto', $issue->fresh()->estado);

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", [
            'estado' => 'en_progreso',
        ]);
        $resp->assertStatus(200);
        $resp->assertJsonPath('estado', 'en_progreso');

        $filaAuto = OpenIssueActualizacion::where('issue_id', $issue->id)
            ->where('tipo', 'cambio_estado')
            ->whereNull('item_id')
            ->first();
        $this->assertNotNull($filaAuto);
        $this->assertSame('abierto', $filaAuto->estado_anterior);
        $this->assertSame('en_progreso', $filaAuto->estado_nuevo);
        $this->assertStringContainsString('avance en un item', $filaAuto->texto);

        // El issue ya está en_progreso (no 'abierto'): pasar el item a 'hecho'
        // NO debe generar una segunda fila automática de cambio_estado, y el
        // issue JAMÁS pasa a 'cerrado' solo por esto.
        $resp2 = $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", [
            'estado' => 'hecho',
        ]);
        $resp2->assertStatus(200);
        $resp2->assertJsonPath('estado', 'en_progreso');

        $this->assertSame(
            1,
            OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'cambio_estado')->whereNull('item_id')->count()
        );
        $this->assertNotSame('cerrado', $issue->fresh()->estado);
    }

    // =========================================================================
    // 5: Progreso (descartados excluidos, completo)
    // =========================================================================

    public function test_progreso_excluye_descartados_y_marca_completo_cuando_corresponde(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        // Issue A: 1 hecho + 1 descartado -> total=1 (excluye descartado), completo=true.
        $issueA = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'items' => [['titulo' => 'A1'], ['titulo' => 'A2']],
        ]);
        $itemsA = OpenIssueItem::where('issue_id', $issueA->id)->orderBy('orden')->get();

        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issueA->id}/items/{$itemsA[0]->id}/estado", ['estado' => 'hecho'])->assertStatus(200);
        $respA = $this->postJson("/api/open-issues/{$issueA->id}/items/{$itemsA[1]->id}/estado", ['estado' => 'descartado']);
        $respA->assertStatus(200);

        $this->assertSame(1, $respA->json('progreso.total'));
        $this->assertSame(1, $respA->json('progreso.hechos'));
        $this->assertSame(1, $respA->json('progreso.descartados'));
        $this->assertTrue($respA->json('progreso.completo'));
        $this->assertSame(100, $respA->json('progreso.porcentaje'));

        // Issue B: 1 hecho + 1 pendiente -> total=2, hechos=1, no completo.
        $issueB = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'items' => [['titulo' => 'B1'], ['titulo' => 'B2']],
        ]);
        $itemsB = OpenIssueItem::where('issue_id', $issueB->id)->orderBy('orden')->get();

        $respB = $this->postJson("/api/open-issues/{$issueB->id}/items/{$itemsB[0]->id}/estado", ['estado' => 'hecho']);
        $respB->assertStatus(200);

        $this->assertSame(2, $respB->json('progreso.total'));
        $this->assertSame(1, $respB->json('progreso.hechos'));
        $this->assertFalse($respB->json('progreso.completo'));
        $this->assertSame(50, $respB->json('progreso.porcentaje'));
    }

    // =========================================================================
    // 6: Comentario ligado a un item
    // =========================================================================

    public function test_comentario_con_item_id_valido_queda_ligado_y_con_item_de_otro_issue_da_422(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue1 = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item1 = $this->crearItemVia($issue1, $creador);

        $issue2 = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item2 = $this->crearItemVia($issue2, $creador);

        Passport::actingAs($creador);
        $ok = $this->postJson("/api/open-issues/{$issue1->id}/actualizaciones", [
            'texto' => 'Comentario sobre el item',
            'item_id' => $item1->id,
        ]);
        $ok->assertStatus(201);
        $ok->assertJsonPath('actualizacion.item_id', $item1->id);
        $ok->assertJsonPath('actualizacion.item.id', $item1->id);

        $ajeno = $this->postJson("/api/open-issues/{$issue1->id}/actualizaciones", [
            'texto' => 'Comentario con item de otro issue',
            'item_id' => $item2->id,
        ]);
        $ajeno->assertStatus(422);
        $ajeno->assertJsonValidationErrors(['item_id']);
    }

    // =========================================================================
    // 7: 404 de item ajeno en las rutas de item
    // =========================================================================

    public function test_item_que_no_pertenece_al_issue_da_404_en_las_rutas_de_item(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue1 = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $issue2 = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item2 = $this->crearItemVia($issue2, $creador);

        Passport::actingAs($creador);

        $editar = $this->putJson("/api/open-issues/{$issue1->id}/items/{$item2->id}", ['titulo' => 'No debería aplicar']);
        $editar->assertStatus(404);

        $estado = $this->postJson("/api/open-issues/{$issue1->id}/items/{$item2->id}/estado", ['estado' => 'hecho']);
        $estado->assertStatus(404);
    }

    // =========================================================================
    // 8: Responsable no involucrado queda involucrado y notificado
    // =========================================================================

    public function test_asignar_responsable_no_involucrado_lo_agrega_como_involucrado_y_lo_notifica(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $responsable = $this->usuario($this->departamento('Otro'), ['name' => 'Responsable Ajeno']);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $this->assertFalse(OpenIssueInvolucrado::where('issue_id', $issue->id)->where('user_id', $responsable->id)->exists());

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [['titulo' => 'Punto con responsable', 'responsable_id' => $responsable->id]],
        ]);
        $resp->assertStatus(201);

        $fila = OpenIssueInvolucrado::where('issue_id', $issue->id)->where('user_id', $responsable->id)->first();
        $this->assertNotNull($fila);
        $this->assertSame(OpenIssueInvolucrado::ORIGEN_MANUAL, $fila->origen);

        $this->assertTrue(OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'involucrado_agregado')->exists());
        $this->assertTrue($this->tieneNotificacionOI($responsable, $issue->id, 'te asignó el item'));
    }

    // =========================================================================
    // 9: Tercero sin alcance -> 403 en las tres rutas
    // =========================================================================

    public function test_tercero_sin_alcance_recibe_403_en_las_tres_rutas_de_items(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $ajeno = $this->usuario($this->departamento('Depósito'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador);

        Passport::actingAs($ajeno);

        $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => [['titulo' => 'Intento ajeno']]])
            ->assertStatus(403);

        $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", ['titulo' => 'Intento ajeno'])
            ->assertStatus(403);

        $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", ['estado' => 'hecho'])
            ->assertStatus(403);
    }

    // =========================================================================
    // 10: Issue cerrado -> 422 en las tres rutas
    // =========================================================================

    public function test_issue_cerrado_rechaza_las_tres_rutas_de_items_con_422(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador);

        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issue->id}/cerrar")->assertStatus(200);

        $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => [['titulo' => 'No debería poder']]])
            ->assertStatus(422);

        $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", ['titulo' => 'No debería poder'])
            ->assertStatus(422);

        $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", ['estado' => 'hecho'])
            ->assertStatus(422);
    }

    // =========================================================================
    // 11: Notificaciones (campana nunca al actor) y mail 'item_estado' encolado
    // =========================================================================

    public function test_cambio_de_estado_de_item_notifica_a_involucrados_menos_al_actor_y_encola_mail(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoCreador = $this->departamento('IT');
        $creador = $this->usuario($deptoCreador);
        $u1 = $this->usuario($deptoCreador, ['name' => 'Testigo Uno']);

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$u1->id],
        ]);
        $item = $this->crearItemVia($issue, $creador);

        Queue::fake();

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", ['estado' => 'hecho']);
        $resp->assertStatus(200);

        // Campana: u1 sí, el actor (creador) nunca.
        $this->assertTrue($this->tieneNotificacionOI($u1, $issue->id, 'marcó como Hecho'));
        $this->assertSame(
            0,
            Notificacion::where('open_issue_id', $issue->id)
                ->where('mensaje', 'like', '%marcó como Hecho%')
                ->where('usuario_creador_id', $creador->id)
                ->count()
        );

        // Mail: evento 'item_estado' encolado solo para u1.
        $jobsU1 = $this->jobsMailOpenIssue('item_estado', $u1->email);
        $this->assertCount(1, $jobsU1);
        $this->assertSame('Hecho', $jobsU1->first()->mailable()->estadoNuevo);
        $this->assertCount(0, $this->jobsMailOpenIssue('item_estado', $creador->email));
    }

    public function test_agregar_items_no_encola_mail_solo_campana(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoCreador = $this->departamento('IT');
        $creador = $this->usuario($deptoCreador);
        $u1 = $this->usuario($deptoCreador, ['name' => 'Testigo']);

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$u1->id],
        ]);

        Queue::fake();

        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => [['titulo' => 'Nuevo punto']]])
            ->assertStatus(201);

        $this->assertTrue($this->tieneNotificacionOI($u1, $issue->id, 'agregó 1 item'));
        Queue::assertNotPushed(SendHheeMailJob::class);
    }

    // =========================================================================
    // 12: filaListado con items_total e items_hechos
    // =========================================================================

    public function test_listado_incluye_items_total_e_items_hechos_sin_contar_descartados(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'items' => [['titulo' => '1'], ['titulo' => '2'], ['titulo' => '3']],
        ]);
        $items = OpenIssueItem::where('issue_id', $issue->id)->orderBy('orden')->get();

        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issue->id}/items/{$items[0]->id}/estado", ['estado' => 'hecho'])->assertStatus(200);
        $this->postJson("/api/open-issues/{$issue->id}/items/{$items[1]->id}/estado", ['estado' => 'descartado'])->assertStatus(200);
        // items[2] queda 'pendiente'.

        $resp = $this->getJson('/api/open-issues?per_page=100');
        $resp->assertStatus(200);

        $fila = collect($resp->json('data'))->firstWhere('id', $issue->id);
        $this->assertNotNull($fila);
        // total no-descartados: hecho + pendiente = 2. hechos: 1.
        $this->assertSame(2, $fila['items_total']);
        $this->assertSame(1, $fila['items_hechos']);
    }

    // =========================================================================
    // 13: catalogos.item_estados
    // =========================================================================

    public function test_catalogos_incluye_item_estados(): void
    {
        $creador = $this->usuario($this->departamento('IT'));

        Passport::actingAs($creador);
        $resp = $this->getJson('/api/open-issues/catalogos');
        $resp->assertStatus(200);
        $resp->assertJsonCount(4, 'item_estados');

        $valores = collect($resp->json('item_estados'))->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['pendiente', 'en_progreso', 'hecho', 'descartado'], $valores);

        $pendiente = collect($resp->json('item_estados'))->firstWhere('value', 'pendiente');
        $this->assertSame('Pendiente', $pendiente['label']);
        $this->assertSame('default', $pendiente['color']);
    }

    // =========================================================================
    // Extra: edición de item (regla 8) — registra item_editado solo si cambió algo
    // =========================================================================

    public function test_editar_item_registra_item_editado_solo_si_cambio_algo(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador, ['titulo' => 'Título original']);

        Passport::actingAs($creador);

        $sinCambios = $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", ['titulo' => 'Título original']);
        $sinCambios->assertStatus(200);
        $this->assertSame(0, OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'item_editado')->count());

        $conCambios = $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", ['titulo' => 'Título editado']);
        $conCambios->assertStatus(200);
        $this->assertSame(1, OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'item_editado')->count());

        $itemEditado = collect($conCambios->json('items'))->firstWhere('id', $item->id);
        $this->assertSame('Título editado', $itemEditado['titulo']);
    }
}
