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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Casos de borde REALES del anexo "Items dentro de un Open Issue" (§10 de
 * SPEC-open-issues.md) que tests/Feature/OpenIssuesItemsTest.php no cubre:
 *
 *  1. Contrato real con el front: multipart/FormData de verdad (con
 *     UploadedFile::fake()) para POST /open-issues con items[] + archivo, y
 *     POST /actualizaciones con item_id + archivo.
 *  2. Ids que llegan como STRING (como los manda FormData: "5", no 5).
 *  3. Concurrencia lógica / máquina de estados: dos items distintos que
 *     empujan el issue a en_progreso, descartar TODOS los items, reabrir.
 *  4. Alcance: gerente ve pero no escribe; depto destino escribe sin ser
 *     involucrado manual.
 *  5. Responsable: ya-involucrado no duplica ni renotifica el alta, quitar
 *     responsable por JSON, autoasignación no notifica al actor.
 *  6. Límites: título 300/301, tope de 50 contando los YA existentes.
 *  7. N+1 de GET /open-issues/{id} con muchos items/actualizaciones.
 *
 * No modifica OpenIssuesItemsTest.php. Mismo criterio de DB (sqlite
 * :memory:) y mismo patrón de limpieza de archivos que OpenIssuesCircuitoTest.
 */
class OpenIssuesItemsBordesTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers de armado (copiados de OpenIssuesItemsTest: no se puede heredar
    // de una clase de test sin arrastrar sus propios test_* methods).
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

    private function tieneNotificacionOI(User $destinatario, int $issueId, string $fragmentoMensaje): bool
    {
        return Notificacion::where('usuario_creador_id', $destinatario->id)
            ->where('tipo', 'open_issue')
            ->where('open_issue_id', $issueId)
            ->where('mensaje', 'like', "%{$fragmentoMensaje}%")
            ->exists();
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

    /**
     * Inserta items/actualizaciones directo en DB (sin pasar por HTTP): solo
     * para armar el fixture del test de N+1, donde lo que se mide es el GET,
     * no el alta.
     */
    private function popularItemsYActualizaciones(OpenIssue $issue, User $actor, int $items, int $actualizaciones): void
    {
        for ($i = 1; $i <= $items; $i++) {
            OpenIssueItem::create([
                'issue_id' => $issue->id,
                'titulo' => "Item {$i}",
                'estado' => 'pendiente',
                'creado_por_id' => $actor->id,
                'orden' => $i,
            ]);
        }

        for ($i = 1; $i <= $actualizaciones; $i++) {
            OpenIssueActualizacion::create([
                'issue_id' => $issue->id,
                'user_id' => $actor->id,
                'tipo' => 'comentario',
                'texto' => "Comentario {$i}",
                'created_at' => now(),
            ]);
        }
    }

    // =========================================================================
    // 1: Contrato real con el front — multipart/FormData de verdad
    // =========================================================================

    public function test_store_por_multipart_real_con_items_anidados_responsable_string_y_archivo(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $responsable = $this->usuario($this->departamento('Otro'), ['name' => 'Resp Multipart']);

        Passport::actingAs($creador);

        $archivo = UploadedFile::fake()->image('foto.jpg');

        // Igual forma que ModalCrearOpenIssue -> buildOpenIssueFormData: items
        // como array de objetos (items[0][titulo], items[1][titulo]/[responsable_id])
        // + archivo, todo en una request multipart real (no postJson).
        $resp = $this->post('/api/open-issues', [
            'titulo' => 'Issue creado por multipart real',
            'departamento_destino_id' => (string) $deptoDestino->id,
            'items' => [
                ['titulo' => 'Item multipart 1'],
                ['titulo' => 'Item multipart 2', 'responsable_id' => (string) $responsable->id],
            ],
            'archivo' => $archivo,
        ], ['Accept' => 'application/json']);

        $nombreArchivo = null;

        try {
            $resp->assertStatus(201);
            $resp->assertJsonCount(2, 'items');

            $item2 = collect($resp->json('items'))->firstWhere('titulo', 'Item multipart 2');
            $this->assertNotNull($item2);
            $this->assertNotNull($item2['responsable']);
            $this->assertSame($responsable->id, $item2['responsable']['id']);

            // El responsable no era involucrado: queda agregado (origen manual) y notificado.
            $this->assertTrue(
                OpenIssueInvolucrado::where('issue_id', $resp->json('id'))
                    ->where('user_id', $responsable->id)
                    ->where('origen', OpenIssueInvolucrado::ORIGEN_MANUAL)
                    ->exists()
            );
            $this->assertTrue($this->tieneNotificacionOI($responsable, $resp->json('id'), 'te asignó el item'));

            // El archivo (sin texto_inicial) igual genera un comentario inicial con adjunto (crear(), §3.4 paso 6).
            $comentario = OpenIssueActualizacion::where('issue_id', $resp->json('id'))->where('tipo', 'comentario')->first();
            $this->assertNotNull($comentario);
            $nombreArchivo = $comentario->archivo;
            $this->assertNotNull($nombreArchivo);
            $this->assertTrue(File::exists(public_path('storage/archivos/' . $nombreArchivo)));
        } finally {
            if ($nombreArchivo) {
                File::delete(public_path('storage/archivos/' . $nombreArchivo));
            }
        }
    }

    public function test_actualizacion_por_multipart_real_con_item_id_string_y_archivo_queda_ligada(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador);

        Passport::actingAs($creador);
        $archivo = UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf');

        $resp = $this->post("/api/open-issues/{$issue->id}/actualizaciones", [
            'texto' => 'Comentario multipart con item',
            'item_id' => (string) $item->id,
            'archivo' => $archivo,
        ], ['Accept' => 'application/json']);

        $nombreArchivo = null;

        try {
            $resp->assertStatus(201);
            $resp->assertJsonPath('actualizacion.item_id', $item->id);
            $resp->assertJsonPath('actualizacion.item.id', $item->id);

            $nombreArchivo = $resp->json('actualizacion.archivo_nombre');
            $this->assertNotNull($nombreArchivo);
            $this->assertNotNull($resp->json('actualizacion.archivo_url'));
            $this->assertTrue(File::exists(public_path('storage/archivos/' . $nombreArchivo)));
        } finally {
            if ($nombreArchivo) {
                File::delete(public_path('storage/archivos/' . $nombreArchivo));
            }
        }
    }

    // =========================================================================
    // 2: Ids como string
    // =========================================================================

    public function test_responsable_id_como_string_en_edicion_de_item_asigna_correctamente(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $responsable = $this->usuario($this->departamento('Otro'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador);

        Passport::actingAs($creador);
        $resp = $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", [
            'titulo' => $item->titulo,
            'responsable_id' => (string) $responsable->id,
        ]);
        $resp->assertStatus(200);

        $itemActualizado = collect($resp->json('items'))->firstWhere('id', $item->id);
        $this->assertNotNull($itemActualizado['responsable']);
        $this->assertSame($responsable->id, $itemActualizado['responsable']['id']);

        $item->refresh();
        $this->assertEquals($responsable->id, $item->responsable_id);
    }

    /**
     * Defensivo: si algún día un cliente manda el string literal "null" (bug
     * típico de un front que serializa mal), el backend debe rechazarlo con
     * 422 -- NO aceptarlo silenciosamente e intentar guardarlo como FK.
     */
    public function test_responsable_id_string_literal_null_es_rechazado_con_422(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [['titulo' => 'Item con responsable string "null"', 'responsable_id' => 'null']],
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['items.0.responsable_id']);
        $this->assertSame(0, OpenIssueItem::where('issue_id', $issue->id)->count());
    }

    // =========================================================================
    // 3: Concurrencia lógica / máquina de estados
    // =========================================================================

    public function test_dos_items_distintos_que_avanzan_generan_una_sola_fila_automatica_de_cambio_estado(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item1 = $this->crearItemVia($issue, $creador, ['titulo' => 'Item uno']);
        $item2 = $this->crearItemVia($issue, $creador, ['titulo' => 'Item dos']);

        $this->assertSame('abierto', $issue->fresh()->estado);

        Passport::actingAs($creador);
        $resp1 = $this->postJson("/api/open-issues/{$issue->id}/items/{$item1->id}/estado", ['estado' => 'en_progreso']);
        $resp1->assertStatus(200);
        $resp1->assertJsonPath('estado', 'en_progreso');

        // Segundo item, DISTINTO del primero: el issue ya está en_progreso, no
        // debe generar una segunda fila automática de cambio_estado.
        $resp2 = $this->postJson("/api/open-issues/{$issue->id}/items/{$item2->id}/estado", ['estado' => 'en_progreso']);
        $resp2->assertStatus(200);
        $resp2->assertJsonPath('estado', 'en_progreso');

        $this->assertSame(
            1,
            OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'cambio_estado')->whereNull('item_id')->count()
        );
    }

    public function test_descartar_todos_los_items_deja_progreso_total_en_cero_y_no_completo(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'items' => [['titulo' => 'A'], ['titulo' => 'B']],
        ]);
        $items = OpenIssueItem::where('issue_id', $issue->id)->orderBy('orden')->get();

        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issue->id}/items/{$items[0]->id}/estado", ['estado' => 'descartado'])->assertStatus(200);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items/{$items[1]->id}/estado", ['estado' => 'descartado']);
        $resp->assertStatus(200);

        $this->assertSame(0, $resp->json('progreso.total'));
        $this->assertSame(0, $resp->json('progreso.hechos'));
        $this->assertSame(2, $resp->json('progreso.descartados'));
        $this->assertFalse($resp->json('progreso.completo'));
        $this->assertSame(0, $resp->json('progreso.porcentaje'));
    }

    public function test_reabrir_el_issue_permite_volver_a_gestionar_items(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador);

        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issue->id}/cerrar")->assertStatus(200);

        // Congelado: las tres rutas de items rechazan con 422.
        $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => [['titulo' => 'No debería']]])
            ->assertStatus(422);

        $this->postJson("/api/open-issues/{$issue->id}/reabrir")->assertStatus(200);

        // Reabierto: las tres rutas vuelven a funcionar.
        $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => [['titulo' => 'Ahora sí']]])
            ->assertStatus(201);

        $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", ['titulo' => 'Editado tras reabrir'])
            ->assertStatus(200);

        $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", ['estado' => 'en_progreso'])
            ->assertStatus(200);
    }

    // =========================================================================
    // 4: Alcance
    // =========================================================================

    public function test_gerente_no_involucrado_ve_items_pero_recibe_403_en_las_tres_rutas_de_escritura(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoGerente = $this->departamento('Gerencia');
        $creador = $this->usuario($this->departamento('IT'));
        $gerente = $this->usuario($deptoGerente, ['rol' => 'gerente']);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador);

        $this->assertFalse(OpenIssueInvolucrado::where('issue_id', $issue->id)->where('user_id', $gerente->id)->exists());

        Passport::actingAs($gerente);

        $verResp = $this->getJson("/api/open-issues/{$issue->id}");
        $verResp->assertStatus(200);
        $verResp->assertJsonCount(1, 'items');

        $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => [['titulo' => 'Intento gerente']]])
            ->assertStatus(403);

        $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", ['titulo' => 'Intento gerente'])
            ->assertStatus(403);

        $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", ['estado' => 'hecho'])
            ->assertStatus(403);
    }

    public function test_usuario_del_departamento_destino_puede_gestionar_items_sin_ser_involucrado_manual(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $miembroDestino = $this->usuario($deptoDestino, ['name' => 'Miembro Calidad']);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $this->assertFalse(OpenIssueInvolucrado::where('issue_id', $issue->id)->where('user_id', $miembroDestino->id)->exists());

        Passport::actingAs($miembroDestino);

        $alta = $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => [['titulo' => 'Item de depto destino']]]);
        $alta->assertStatus(201);

        $item = OpenIssueItem::where('issue_id', $issue->id)->firstOrFail();

        $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", ['titulo' => 'Editado por depto destino'])
            ->assertStatus(200);

        $this->postJson("/api/open-issues/{$issue->id}/items/{$item->id}/estado", ['estado' => 'en_progreso'])
            ->assertStatus(200);
    }

    // =========================================================================
    // 5: Responsable
    // =========================================================================

    public function test_asignar_responsable_ya_involucrado_no_duplica_fila_ni_reregistra_involucrado_agregado(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $otro = $this->usuario($this->departamento('Otro'), ['name' => 'Ya Involucrado']);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issue->id}/involucrados", ['user_ids' => [$otro->id]])->assertStatus(200);

        $this->assertSame(1, OpenIssueInvolucrado::where('issue_id', $issue->id)->where('user_id', $otro->id)->count());
        $this->assertSame(
            1,
            OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'involucrado_agregado')->count()
        );

        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [['titulo' => 'Item con responsable ya involucrado', 'responsable_id' => $otro->id]],
        ]);
        $resp->assertStatus(201);

        // No se duplicó la fila de involucrado ni se volvió a registrar 'involucrado_agregado'...
        $this->assertSame(1, OpenIssueInvolucrado::where('issue_id', $issue->id)->where('user_id', $otro->id)->count());
        $this->assertSame(
            1,
            OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'involucrado_agregado')->count()
        );

        // ...pero SÍ se notifica la asignación del item en sí (independiente de si ya estaba involucrado).
        $this->assertTrue($this->tieneNotificacionOI($otro, $issue->id, 'te asignó el item'));
    }

    public function test_quitar_responsable_con_null_explicito_por_json_limpia_el_campo(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $responsable = $this->usuario($this->departamento('Otro'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $item = $this->crearItemVia($issue, $creador, ['responsable_id' => $responsable->id]);

        $item->refresh();
        $this->assertEquals($responsable->id, $item->responsable_id);

        Passport::actingAs($creador);
        $resp = $this->putJson("/api/open-issues/{$issue->id}/items/{$item->id}", [
            'titulo' => $item->titulo,
            'responsable_id' => null,
        ]);
        $resp->assertStatus(200);

        $item->refresh();
        $this->assertNull($item->responsable_id);

        $itemActualizado = collect($resp->json('items'))->firstWhere('id', $item->id);
        $this->assertNull($itemActualizado['responsable']);

        $fila = OpenIssueActualizacion::where('issue_id', $issue->id)
            ->where('tipo', 'item_editado')
            ->where('item_id', $item->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($fila);
        $this->assertStringContainsString('responsable', $fila->texto);
    }

    public function test_asignarse_a_uno_mismo_como_responsable_no_notifica_ni_encola_mail_al_actor(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Queue::fake();

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [['titulo' => 'Item autoasignado', 'responsable_id' => $creador->id]],
        ]);
        $resp->assertStatus(201);

        $itemCreado = collect($resp->json('items'))->firstWhere('titulo', 'Item autoasignado');
        $this->assertNotNull($itemCreado['responsable']);
        $this->assertSame($creador->id, $itemCreado['responsable']['id']);

        $this->assertSame(
            0,
            Notificacion::where('open_issue_id', $issue->id)
                ->where('usuario_creador_id', $creador->id)
                ->where('mensaje', 'like', '%te asignó el item%')
                ->count()
        );
        $this->assertCount(0, $this->jobsMailOpenIssue('involucrado', $creador->email));
    }

    // =========================================================================
    // 6: Límites
    // =========================================================================

    public function test_titulo_de_300_caracteres_es_valido_y_301_da_422(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);

        $titulo300 = str_repeat('a', 300);
        $ok = $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => [['titulo' => $titulo300]]]);
        $ok->assertStatus(201);
        $this->assertSame($titulo300, OpenIssueItem::where('issue_id', $issue->id)->first()->titulo);

        $titulo301 = str_repeat('a', 301);
        $mal = $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => [['titulo' => $titulo301]]]);
        $mal->assertStatus(422);
        $mal->assertJsonValidationErrors(['items.0.titulo']);
        // Sigue habiendo 1 solo item: el título de 301 no se insertó.
        $this->assertSame(1, OpenIssueItem::where('issue_id', $issue->id)->count());
    }

    public function test_tope_de_50_items_cuenta_los_existentes_y_rechaza_sin_insertar_nada(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);

        $items48 = array_map(fn ($i) => ['titulo' => "Item existente {$i}"], range(1, 48));
        $this->postJson("/api/open-issues/{$issue->id}/items", ['items' => $items48])->assertStatus(201);
        $this->assertSame(48, OpenIssueItem::where('issue_id', $issue->id)->count());

        $resp = $this->postJson("/api/open-issues/{$issue->id}/items", [
            'items' => [['titulo' => 'Nuevo 1'], ['titulo' => 'Nuevo 2'], ['titulo' => 'Nuevo 3']],
        ]);
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['items']);

        // 48 + 3 = 51 > 50: el lote entero se rechaza, nada se inserta (todo o nada).
        $this->assertSame(48, OpenIssueItem::where('issue_id', $issue->id)->count());
    }

    // =========================================================================
    // 7: Sin N+1 en GET /open-issues/{id}
    // =========================================================================

    public function test_show_no_crece_en_queries_con_mas_items_y_actualizaciones(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issueChico = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $this->popularItemsYActualizaciones($issueChico, $creador, 2, 2);

        $issueGrande = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $this->popularItemsYActualizaciones($issueGrande, $creador, 20, 40);

        Passport::actingAs($creador);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("/api/open-issues/{$issueChico->id}")->assertStatus(200);
        $queriesChico = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->getJson("/api/open-issues/{$issueGrande->id}")->assertStatus(200);
        $queriesGrande = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            $queriesChico + 3,
            $queriesGrande,
            "El show pasó de {$queriesChico} queries (2 items/2 actualizaciones) a {$queriesGrande} queries (20 items/40 actualizaciones): sospecha de N+1."
        );
    }
}
