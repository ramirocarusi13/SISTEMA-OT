<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Notificacion;
use App\Models\OpenIssue;
use App\Models\OpenIssueActualizacion;
use App\Models\OpenIssueInvolucrado;
use App\Models\OrdenTrabajo;
use App\Models\SolicitudHhee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Test de Feature end-to-end del módulo Open Issues: alta (con involucrados
 * sueltos/departamento/snapshot/duplicados), notificaciones (matriz de
 * App\Support\OpenIssueNotificador), alcance de lectura/escritura
 * (App\Support\AlcanceOpenIssues), timeline y transiciones de estado
 * (App\Support\OpenIssueFlujo), congelamiento en 'cerrado', adjuntos,
 * validaciones 422, filtros del listado y la regresión de
 * NotificacionesController::index() mezclando tipo 'ot'/'hhee'/'open_issue'.
 *
 * Entorno de DB: sqlite :memory: (ver phpunit.xml y tests/Feature/HheeCircuitoTest.php,
 * mismo criterio: no tocar la base productiva SQL Server de ordenes_sar).
 */
class OpenIssuesCircuitoTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers de armado (copiados de HheeCircuitoTest) + propios del módulo
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

    /**
     * Crea un issue vía HTTP (POST /api/open-issues) actuando como $actor y
     * devuelve el modelo ya persistido. $overrides DEBE incluir
     * 'departamento_destino_id' (es requerido por la validación del alta).
     */
    private function crearIssueVia(User $actor, array $overrides = []): OpenIssue
    {
        Passport::actingAs($actor);

        $resp = $this->postJson('/api/open-issues', $this->payloadIssue($overrides));
        $resp->assertStatus(201);

        return OpenIssue::findOrFail($resp->json('id'));
    }

    private function tieneNotificacionOI(User $destinatario, int $issueId, string $fragmentoMensaje): bool
    {
        return Notificacion::where('usuario_creador_id', $destinatario->id)
            ->where('tipo', 'open_issue')
            ->where('open_issue_id', $issueId)
            ->where('mensaje', 'like', "%{$fragmentoMensaje}%")
            ->exists();
    }

    // =========================================================================
    // 1-2: Alta básica + creador involucrado
    // =========================================================================

    public function test_crear_issue_devuelve_201_con_detalle_y_flags(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        Passport::actingAs($creador);
        $resp = $this->postJson('/api/open-issues', $this->payloadIssue([
            'departamento_destino_id' => $deptoDestino->id,
        ]));

        $resp->assertStatus(201);
        $resp->assertJsonPath('estado', 'abierto');
        $resp->assertJsonPath('prioridad', 'media'); // default de config('open_issues.prioridad_default')
        $resp->assertJsonPath('flags.puede_editar', true);
        $resp->assertJsonPath('flags.es_creador', true);
        $resp->assertJsonCount(1, 'actualizaciones');
        $resp->assertJsonPath('actualizaciones.0.tipo', 'apertura');
    }

    public function test_creador_queda_involucrado_con_origen_creador(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        $fila = OpenIssueInvolucrado::where('issue_id', $issue->id)->where('user_id', $creador->id)->first();

        $this->assertNotNull($fila);
        $this->assertSame(OpenIssueInvolucrado::ORIGEN_CREADOR, $fila->origen);
        $this->assertNull($fila->departamento_id);
        $this->assertSame($creador->id, $fila->agregado_por_id);
    }

    // =========================================================================
    // 3-5: Expansión de departamentos, snapshot, duplicados
    // =========================================================================

    public function test_crear_con_departamentos_ids_expande_a_todos_los_users_del_depto(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoOrigen = $this->departamento('Producción');
        $creador = $this->usuario($this->departamento('IT'));
        $u1 = $this->usuario($deptoOrigen, ['name' => 'Uno']);
        $u2 = $this->usuario($deptoOrigen, ['name' => 'Dos']);
        $u3 = $this->usuario($deptoOrigen, ['name' => 'Tres']);

        $resp = null;
        Passport::actingAs($creador);
        $resp = $this->postJson('/api/open-issues', $this->payloadIssue([
            'departamento_destino_id' => $deptoDestino->id,
            'departamentos_ids' => [$deptoOrigen->id],
        ]));
        $resp->assertStatus(201);

        $issueId = $resp->json('id');

        $filasDepto = OpenIssueInvolucrado::where('issue_id', $issueId)->where('origen', 'departamento')->get();
        $this->assertCount(3, $filasDepto);
        foreach ($filasDepto as $fila) {
            $this->assertSame($deptoOrigen->id, $fila->departamento_id);
        }
        $this->assertEqualsCanonicalizing(
            [$u1->id, $u2->id, $u3->id],
            $filasDepto->pluck('user_id')->map(fn ($v) => (int) $v)->all()
        );

        // 4 involucrados: creador + los 3 del depto expandido.
        $resp->assertJsonCount(4, 'involucrados');
        $this->assertSame(4, OpenIssueInvolucrado::where('issue_id', $issueId)->count());
    }

    public function test_expansion_de_departamento_es_snapshot_no_dinamica(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoOrigen = $this->departamento('Producción');
        $creador = $this->usuario($this->departamento('IT'));
        $existente = $this->usuario($deptoOrigen, ['name' => 'Ya estaba']);

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'departamentos_ids' => [$deptoOrigen->id],
        ]);

        // Usuario nuevo que entra al depto DESPUÉS del alta.
        $nuevo = $this->usuario($deptoOrigen, ['name' => 'Entró después']);

        Passport::actingAs($creador);
        $show = $this->getJson("/api/open-issues/{$issue->id}");
        $show->assertStatus(200);

        $idsInvolucrados = collect($show->json('involucrados'))->pluck('user_id')->all();
        $this->assertContains($existente->id, $idsInvolucrados);
        $this->assertContains($creador->id, $idsInvolucrados);
        $this->assertNotContains($nuevo->id, $idsInvolucrados);
    }

    public function test_persona_suelta_gana_sobre_departamento_y_duplicados_se_ignoran(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoOrigen = $this->departamento('Producción');
        $creador = $this->usuario($this->departamento('IT'));
        $x = $this->usuario($deptoOrigen, ['name' => 'X Ambiguo']);

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$x->id],
            'departamentos_ids' => [$deptoOrigen->id],
        ]);

        // Una sola fila para X, con origen 'manual' (personas sueltas ganan).
        $filasX = OpenIssueInvolucrado::where('issue_id', $issue->id)->where('user_id', $x->id)->get();
        $this->assertCount(1, $filasX);
        $this->assertSame(OpenIssueInvolucrado::ORIGEN_MANUAL, $filasX->first()->origen);

        $totalAntes = OpenIssueInvolucrado::where('issue_id', $issue->id)->count();

        // Reenviar el mismo departamento: no debe fallar ni duplicar.
        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/involucrados", [
            'departamento_ids' => [$deptoOrigen->id],
        ]);

        $resp->assertStatus(200);
        $this->assertSame([], $resp->json('agregados'));
        $this->assertNotEmpty($resp->json('ignorados'));
        $this->assertSame($totalAntes, OpenIssueInvolucrado::where('issue_id', $issue->id)->count());
    }

    // =========================================================================
    // 6-7: Notificaciones (matriz de OpenIssueNotificador)
    // =========================================================================

    public function test_crear_notifica_solo_a_involucrados_y_nunca_al_actor(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoCreador = $this->departamento('IT');
        $creador = $this->usuario($deptoCreador);
        $u1 = $this->usuario($deptoCreador, ['name' => 'Involucrado Uno']);
        $u2 = $this->usuario($deptoCreador, ['name' => 'Involucrado Dos']);

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$u1->id, $u2->id],
        ]);

        $this->assertTrue($this->tieneNotificacionOI($u1, $issue->id, 'Te involucraron'));
        $this->assertTrue($this->tieneNotificacionOI($u2, $issue->id, 'Te involucraron'));

        // Nunca al actor (el propio creador).
        $this->assertSame(0, Notificacion::where('open_issue_id', $issue->id)->where('usuario_creador_id', $creador->id)->count());

        // Una sola fila por destinatario.
        $this->assertSame(1, Notificacion::where('open_issue_id', $issue->id)->where('usuario_creador_id', $u1->id)->count());
        $this->assertSame(1, Notificacion::where('open_issue_id', $issue->id)->where('usuario_creador_id', $u2->id)->count());
        $this->assertSame(2, Notificacion::where('open_issue_id', $issue->id)->count());
    }

    public function test_comentario_notifica_a_todos_los_involucrados_menos_al_actor(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoCreador = $this->departamento('IT');
        $creador = $this->usuario($deptoCreador);
        $u1 = $this->usuario($deptoCreador, ['name' => 'Comentarista']);
        $u2 = $this->usuario($deptoCreador, ['name' => 'Testigo']);

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$u1->id, $u2->id],
        ]);

        // Limpio las notificaciones del alta para no confundir el conteo de este paso.
        Notificacion::where('open_issue_id', $issue->id)->delete();

        Passport::actingAs($u1);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", [
            'texto' => 'Comentario de prueba',
        ]);
        $resp->assertStatus(201);

        // N-1 destinatarios (creador + u2), nunca u1 (el actor).
        $this->assertTrue($this->tieneNotificacionOI($creador, $issue->id, 'comentó'));
        $this->assertTrue($this->tieneNotificacionOI($u2, $issue->id, 'comentó'));
        $this->assertSame(0, Notificacion::where('open_issue_id', $issue->id)->where('usuario_creador_id', $u1->id)->count());
        $this->assertSame(2, Notificacion::where('open_issue_id', $issue->id)->count());

        $notif = Notificacion::where('open_issue_id', $issue->id)->where('usuario_creador_id', $creador->id)->first();
        $this->assertNotNull($notif->estado_anterior);
        $this->assertNotNull($notif->estado_nuevo);
        $this->assertSame('abierto', $notif->estado_anterior);
        $this->assertSame('abierto', $notif->estado_nuevo);
    }

    // =========================================================================
    // 8: Flujo completo de estados
    // =========================================================================

    public function test_flujo_completo_comentario_en_progreso_cierre_reapertura(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);

        $comentario = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", [
            'texto' => 'Ya lo estoy viendo',
        ]);
        $comentario->assertStatus(201);
        $comentario->assertJsonPath('actualizacion.tipo', 'comentario');

        $enProgreso = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", [
            'nuevo_estado' => 'en_progreso',
        ]);
        $enProgreso->assertStatus(201);
        $enProgreso->assertJsonPath('actualizacion.tipo', 'cambio_estado');
        $enProgreso->assertJsonPath('actualizacion.estado_anterior', 'abierto');
        $enProgreso->assertJsonPath('actualizacion.estado_nuevo', 'en_progreso');
        $enProgreso->assertJsonPath('issue.estado', 'en_progreso');

        $cerrar = $this->postJson("/api/open-issues/{$issue->id}/cerrar", ['texto' => 'Resuelto']);
        $cerrar->assertStatus(200);
        $cerrar->assertJsonPath('issue.estado', 'cerrado');

        $issue->refresh();
        $this->assertNotNull($issue->fecha_cierre);
        $this->assertSame($creador->id, $issue->cerrado_por_id);
        $this->assertTrue(OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'cierre')->exists());

        $reabrir = $this->postJson("/api/open-issues/{$issue->id}/reabrir", ['texto' => 'Reabierto por error']);
        $reabrir->assertStatus(200);
        $reabrir->assertJsonPath('issue.estado', 'abierto');

        $issue->refresh();
        $this->assertNull($issue->fecha_cierre);
        $this->assertNull($issue->cerrado_por_id);
        $this->assertNotNull($issue->fecha_reapertura);
        $this->assertTrue(OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'reapertura')->exists());
    }

    // =========================================================================
    // 9-12: Alcance de lectura/escritura
    // =========================================================================

    public function test_alcance_del_listado_por_tipo_de_usuario(): void
    {
        $deptoCreador = $this->departamento('IT');
        $deptoDestino = $this->departamento('Calidad');
        $deptoInvolucrado = $this->departamento('Producción');
        $deptoAjeno = $this->departamento('Depósito');

        $creador = $this->usuario($deptoCreador);
        $involucrado = $this->usuario($deptoInvolucrado);
        $userDestino = $this->usuario($deptoDestino);
        $gerente = $this->usuario($deptoAjeno, ['rol' => 'gerente']);
        $ajeno = $this->usuario($deptoAjeno);
        $peerCreador = $this->usuario($deptoCreador); // mismo depto que el creador, sin ser involucrado (§5.4)

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$involucrado->id],
        ]);

        foreach ([$creador, $involucrado, $userDestino, $gerente] as $u) {
            Passport::actingAs($u);
            $resp = $this->getJson('/api/open-issues?per_page=100');
            $resp->assertStatus(200);
            $ids = collect($resp->json('data'))->pluck('id')->all();
            $this->assertContains($issue->id, $ids, "El usuario {$u->id} debería ver el issue");
        }

        Passport::actingAs($ajeno);
        $respAjeno = $this->getJson('/api/open-issues?per_page=100');
        $respAjeno->assertJsonPath('total', 0);

        // §5.4: un usuario del MISMO departamento del creador (sin ser involucrado) NO lo ve.
        Passport::actingAs($peerCreador);
        $respPeer = $this->getJson('/api/open-issues?per_page=100');
        $respPeer->assertJsonPath('total', 0);
    }

    public function test_show_403_para_usuario_sin_alcance_y_404_si_no_existe(): void
    {
        $creador = $this->usuario($this->departamento('IT'));
        $ajeno = $this->usuario($this->departamento('Depósito'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $this->departamento('Calidad')->id]);

        Passport::actingAs($ajeno);
        $resp = $this->getJson("/api/open-issues/{$issue->id}");
        $resp->assertStatus(403);
        $resp->assertJsonStructure(['error']);

        $resp404 = $this->getJson('/api/open-issues/999999');
        $resp404->assertStatus(404);
        $resp404->assertJson(['error' => 'Issue no encontrado']);
    }

    public function test_gerente_ve_pero_no_puede_escribir(): void
    {
        $creador = $this->usuario($this->departamento('IT'));
        $gerente = $this->usuario($this->departamento('Gerencia'), ['rol' => 'gerente']);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $this->departamento('Calidad')->id]);

        Passport::actingAs($gerente);
        $show = $this->getJson("/api/open-issues/{$issue->id}");
        $show->assertStatus(200);
        $show->assertJsonPath('flags.puede_actualizar', false);

        $resp = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", ['texto' => 'Intento comentar']);
        $resp->assertStatus(403);
    }

    public function test_usuario_del_departamento_destino_puede_comentar_sin_estar_involucrado(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $userDestino = $this->usuario($deptoDestino);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($userDestino);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", [
            'texto' => 'Ya lo estamos viendo desde Calidad',
        ]);
        $resp->assertStatus(201);
    }

    // =========================================================================
    // 13-14: Edición y congelamiento en cerrado
    // =========================================================================

    public function test_solo_el_creador_puede_editar_y_registra_actualizacion_edicion(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $involucrado = $this->usuario($this->departamento('Otro'));

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$involucrado->id],
        ]);

        Passport::actingAs($involucrado);
        $respAjena = $this->putJson("/api/open-issues/{$issue->id}", ['titulo' => 'Intento de edición ajena']);
        $respAjena->assertStatus(403);

        Passport::actingAs($creador);
        $respOk = $this->putJson("/api/open-issues/{$issue->id}", [
            'titulo' => 'Título editado',
            'descripcion' => 'Descripción editada',
            'prioridad' => 'alta',
        ]);
        $respOk->assertStatus(200);
        $respOk->assertJsonPath('titulo', 'Título editado');

        $fila = OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'edicion')->first();
        $this->assertNotNull($fila);
        $this->assertStringStartsWith('Editó: ', $fila->texto);
    }

    public function test_issue_cerrado_rechaza_editar_comentar_e_involucrar_con_422(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $involucrado = $this->usuario($this->departamento('Otro'));
        $paraInvolucrar = $this->usuario($this->departamento('Otro2'));

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$involucrado->id],
        ]);

        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issue->id}/cerrar")->assertStatus(200);

        $editar = $this->putJson("/api/open-issues/{$issue->id}", ['titulo' => 'No debería poder']);
        $editar->assertStatus(422);

        $comentar = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", ['texto' => 'No debería poder']);
        $comentar->assertStatus(422);

        $involucrar = $this->postJson("/api/open-issues/{$issue->id}/involucrados", ['user_ids' => [$paraInvolucrar->id]]);
        $involucrar->assertStatus(422);

        $quitar = $this->deleteJson("/api/open-issues/{$issue->id}/involucrados/{$involucrado->id}");
        $quitar->assertStatus(422);
    }

    // =========================================================================
    // 15-16: Transiciones inválidas / actualización vacía
    // =========================================================================

    public function test_transiciones_invalidas_devuelven_422(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issueCerrado = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issueCerrado->id}/cerrar")->assertStatus(200);
        $this->postJson("/api/open-issues/{$issueCerrado->id}/cerrar")->assertStatus(422);

        $issueAbierto = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issueAbierto->id}/reabrir")->assertStatus(422);

        $issueParaEstado = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        Passport::actingAs($creador);
        $respEstadoInvalido = $this->postJson("/api/open-issues/{$issueParaEstado->id}/actualizaciones", [
            'nuevo_estado' => 'cerrado',
        ]);
        $respEstadoInvalido->assertStatus(422);
        $respEstadoInvalido->assertJsonValidationErrors(['nuevo_estado']);
    }

    public function test_actualizacion_vacia_422_y_nuevo_estado_igual_al_actual_se_ignora(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);
        $vacio = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", []);
        $vacio->assertStatus(422);
        $vacio->assertJsonValidationErrors(['texto']);

        $igual = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", [
            'nuevo_estado' => 'abierto', // el issue ya está 'abierto'
            'texto' => 'Sigue abierto, solo comento',
        ]);
        $igual->assertStatus(201);
        $igual->assertJsonPath('actualizacion.tipo', 'comentario');
        $igual->assertJsonPath('actualizacion.estado_anterior', null);
        $igual->assertJsonPath('actualizacion.estado_nuevo', null);
    }

    // =========================================================================
    // 17-18: Involucrados
    // =========================================================================

    public function test_no_se_puede_quitar_al_creador(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $otro = $this->usuario($this->departamento('Otro'));

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$otro->id],
        ]);

        Passport::actingAs($creador);
        $respCreador = $this->deleteJson("/api/open-issues/{$issue->id}/involucrados/{$creador->id}");
        $respCreador->assertStatus(422);

        $respOtro = $this->deleteJson("/api/open-issues/{$issue->id}/involucrados/{$otro->id}");
        $respOtro->assertStatus(200);

        $fila = OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'involucrado_quitado')->first();
        $this->assertNotNull($fila);

        $this->assertTrue($this->tieneNotificacionOI($otro, $issue->id, 'Te quitaron'));
        // Solo el quitado recibe esa campana.
        $this->assertSame(
            0,
            Notificacion::where('open_issue_id', $issue->id)
                ->where('mensaje', 'like', '%Te quitaron%')
                ->where('usuario_creador_id', '!=', $otro->id)
                ->count()
        );
    }

    public function test_quitar_involucrado_inexistente_devuelve_422(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $nuncaInvolucrado = $this->usuario($this->departamento('Otro'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);
        $resp = $this->deleteJson("/api/open-issues/{$issue->id}/involucrados/{$nuncaInvolucrado->id}");
        $resp->assertStatus(422);
        $this->assertStringContainsString('no está involucrado', $resp->json('errors.user_id.0'));
    }

    // =========================================================================
    // 19: Validaciones 422 del alta
    // =========================================================================

    public function test_validaciones_422_del_alta(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        Passport::actingAs($creador);

        $sinTitulo = $this->postJson('/api/open-issues', $this->payloadIssue([
            'departamento_destino_id' => $deptoDestino->id,
            'titulo' => null,
        ]));
        $sinTitulo->assertStatus(422);
        $sinTitulo->assertJsonValidationErrors(['titulo']);

        $tituloLargo = $this->postJson('/api/open-issues', $this->payloadIssue([
            'departamento_destino_id' => $deptoDestino->id,
            'titulo' => str_repeat('a', 201),
        ]));
        $tituloLargo->assertStatus(422);
        $tituloLargo->assertJsonValidationErrors(['titulo']);

        $deptoInexistente = $this->postJson('/api/open-issues', $this->payloadIssue([
            'departamento_destino_id' => 999999,
        ]));
        $deptoInexistente->assertStatus(422);
        $deptoInexistente->assertJsonValidationErrors(['departamento_destino_id']);

        $prioridadInvalida = $this->postJson('/api/open-issues', $this->payloadIssue([
            'departamento_destino_id' => $deptoDestino->id,
            'prioridad' => 'urgente',
        ]));
        $prioridadInvalida->assertStatus(422);
        $prioridadInvalida->assertJsonValidationErrors(['prioridad']);

        $involucradoInexistente = $this->postJson('/api/open-issues', $this->payloadIssue([
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [999999],
        ]));
        $involucradoInexistente->assertStatus(422);
        $involucradoInexistente->assertJsonValidationErrors(['involucrados_ids.0']);
    }

    // =========================================================================
    // 20: Adjuntos
    // =========================================================================

    public function test_adjunto_en_actualizacion_guarda_archivo_y_mime(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        $archivo = UploadedFile::fake()->image('foto.jpg');

        Passport::actingAs($creador);
        $resp = $this->post("/api/open-issues/{$issue->id}/actualizaciones", [
            'texto' => 'Mirá esta foto',
            'archivo' => $archivo,
        ]);

        $nombreArchivo = null;

        try {
            $resp->assertStatus(201);

            $nombreArchivo = $resp->json('actualizacion.archivo_nombre');
            $this->assertNotNull($nombreArchivo);
            $this->assertNotNull($resp->json('actualizacion.archivo_url'));
            $this->assertNotNull($resp->json('actualizacion.mime_type'));

            $actualizacion = OpenIssueActualizacion::findOrFail($resp->json('actualizacion.id'));
            $this->assertSame($nombreArchivo, $actualizacion->archivo);
            $this->assertNotNull($actualizacion->mime_type);

            $this->assertTrue(File::exists(public_path('storage/archivos/' . $nombreArchivo)));
        } finally {
            // ArchivoOrden escribe en public/ (no hay Storage::fake): limpiar el archivo de prueba.
            if ($nombreArchivo) {
                File::delete(public_path('storage/archivos/' . $nombreArchivo));
            }
        }
    }

    // =========================================================================
    // 21: /pendientes
    // =========================================================================

    public function test_pendientes_solo_total_cuenta_no_cerrados_donde_participo(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $otroCreador = $this->usuario($this->departamento('Otro'));

        $issuePropio = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        // Ajeno: $creador no participa (ni es creador, ni involucrado; el depto destino es distinto del suyo).
        $this->crearIssueVia($otroCreador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);
        $resp = $this->getJson('/api/open-issues/pendientes?solo_total=1');
        $resp->assertStatus(200);
        $resp->assertExactJson(['total' => 1]);

        $this->postJson("/api/open-issues/{$issuePropio->id}/cerrar")->assertStatus(200);

        $respDespues = $this->getJson('/api/open-issues/pendientes?solo_total=1');
        $respDespues->assertExactJson(['total' => 0]);
    }

    // =========================================================================
    // 22: /catalogos
    // =========================================================================

    public function test_catalogos_devuelve_estados_prioridades_departamentos_usuarios_y_flags(): void
    {
        $depto = $this->departamento();
        $gerente = $this->usuario($depto, ['rol' => 'gerente']);
        $normal = $this->usuario($depto);

        Passport::actingAs($gerente);
        $resp = $this->getJson('/api/open-issues/catalogos');
        $resp->assertStatus(200);
        $resp->assertJsonStructure([
            'estados',
            'prioridades',
            'tipos_actualizacion',
            'departamentos',
            'usuarios',
            'prioridad_default',
            'adjunto',
            'flags' => ['es_gerente', 'puede_ver_todos', 'mi_user_id', 'mi_departamento_id'],
        ]);
        $resp->assertJsonPath('flags.es_gerente', true);
        $resp->assertJsonPath('flags.puede_ver_todos', true);
        $resp->assertJsonCount(3, 'estados');
        $resp->assertJsonCount(3, 'prioridades');

        Passport::actingAs($normal);
        $respNormal = $this->getJson('/api/open-issues/catalogos');
        $respNormal->assertStatus(200);
        $respNormal->assertJsonPath('flags.es_gerente', false);
        $respNormal->assertJsonPath('flags.puede_ver_todos', false);
    }

    // =========================================================================
    // 23: Filtros del listado
    // =========================================================================

    public function test_listado_filtra_por_estado_prioridad_departamento_texto_mios_y_participo(): void
    {
        $deptoA = $this->departamento('A');
        $deptoB = $this->departamento('B');
        $gerente = $this->usuario($deptoA, ['rol' => 'gerente']);
        $creador1 = $this->usuario($deptoA, ['name' => 'Creador Uno']);
        $creador2 = $this->usuario($deptoB, ['name' => 'Creador Dos']);

        $issue1 = $this->crearIssueVia($creador1, [
            'departamento_destino_id' => $deptoA->id,
            'prioridad' => 'alta',
            'titulo' => 'Problema critico de costura',
        ]);
        $issue2 = $this->crearIssueVia($creador2, [
            'departamento_destino_id' => $deptoB->id,
            'prioridad' => 'baja',
            'titulo' => 'Pedido de repuesto',
        ]);

        Passport::actingAs($gerente);

        // estado[]: ambos siguen 'abierto'.
        $porEstado = $this->getJson('/api/open-issues?' . http_build_query(['estado' => ['abierto']]));
        $porEstado->assertStatus(200);
        $porEstado->assertJsonPath('total', 2);

        // prioridad: solo issue1.
        $porPrioridad = $this->getJson('/api/open-issues?prioridad=alta');
        $porPrioridad->assertJsonPath('total', 1);
        $this->assertSame($issue1->id, collect($porPrioridad->json('data'))->first()['id']);

        // departamento_destino_id: solo issue1.
        $porDepto = $this->getJson("/api/open-issues?departamento_destino_id={$deptoA->id}");
        $porDepto->assertJsonPath('total', 1);
        $this->assertSame($issue1->id, collect($porDepto->json('data'))->first()['id']);

        // texto: solo issue1.
        $porTexto = $this->getJson('/api/open-issues?texto=costura');
        $porTexto->assertJsonPath('total', 1);
        $this->assertSame($issue1->id, collect($porTexto->json('data'))->first()['id']);

        // mios: como creador1, solo su propio issue1.
        Passport::actingAs($creador1);
        $porMios = $this->getJson('/api/open-issues?mios=1');
        $porMios->assertJsonPath('total', 1);
        $this->assertSame($issue1->id, collect($porMios->json('data'))->first()['id']);

        // participo: como creador2, solo issue2 (donde es creador/involucrado).
        Passport::actingAs($creador2);
        $porParticipo = $this->getJson('/api/open-issues?participo=1');
        $porParticipo->assertJsonPath('total', 1);
        $this->assertSame($issue2->id, collect($porParticipo->json('data'))->first()['id']);
    }

    // =========================================================================
    // 24: Regresión de notificaciones OT/HHEE
    // =========================================================================

    public function test_regresion_notificaciones_de_ot_y_hhee_no_cambian(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $otroUsuario = $this->usuario($depto);
        $creadorIssue = $this->usuario($this->departamento('Otro'));

        // Notificación tipo 'ot'.
        $orden = OrdenTrabajo::create([
            'usuario_id' => $otroUsuario->id,
            'titulo' => 'Reparar impresora',
            'estado' => 'creada',
        ]);
        Notificacion::create([
            'orden_trabajo_id' => $orden->id,
            'usuario_creador_id' => $gl->id,
            'usuario_mantenimiento_id' => $otroUsuario->id,
            'estado_anterior' => 'creada',
            'estado_nuevo' => 'aprobada',
            'mensaje' => null,
            'leido' => false,
            'tipo' => 'ot',
        ]);

        // Notificación tipo 'hhee'.
        $solicitudHhee = SolicitudHhee::create([
            'solicitante_id' => $gl->id,
            'departamento_id' => $depto->id,
            'fecha_hhee' => now()->toDateString(),
        ]);
        Notificacion::create([
            'usuario_creador_id' => $gl->id,
            'usuario_mantenimiento_id' => $otroUsuario->id,
            'estado_anterior' => 'pendiente_nivel1',
            'estado_nuevo' => 'pendiente_nivel1',
            'mensaje' => 'Tu solicitud está pendiente de tu firma',
            'leido' => false,
            'tipo' => 'hhee',
            'solicitud_hhee_id' => $solicitudHhee->id,
        ]);

        // Notificación tipo 'open_issue' (por el flujo real: $gl queda involucrado).
        $issue = $this->crearIssueVia($creadorIssue, [
            'departamento_destino_id' => $this->departamento('Calidad')->id,
            'involucrados_ids' => [$gl->id],
        ]);

        Passport::actingAs($gl);
        $resp = $this->getJson('/api/notificaciones');
        $resp->assertStatus(200);

        $notificaciones = collect($resp->json());
        $this->assertCount(3, $notificaciones);

        $notifOt = $notificaciones->firstWhere('tipo', 'ot');
        $this->assertNotNull($notifOt);
        $this->assertStringContainsString('ha cambiado el estado de la orden de trabajo a aprobada', $notifOt['detalle']);

        $notifHhee = $notificaciones->firstWhere('tipo', 'hhee');
        $this->assertNotNull($notifHhee);
        $this->assertStringContainsString("Solicitud HHEE #{$solicitudHhee->id}", $notifHhee['detalle']);

        $notifOi = $notificaciones->firstWhere('tipo', 'open_issue');
        $this->assertNotNull($notifOi);
        $this->assertSame($issue->id, $notifOi['open_issue_id']);
        $this->assertStringContainsString("Open Issue #{$issue->id}", $notifOi['detalle']);
        $this->assertStringContainsString('Te involucraron', $notifOi['detalle']);
    }

    // =========================================================================
    // 25: Counts y última actualización del listado
    // =========================================================================

    public function test_listado_trae_counts_y_ultima_actualizacion(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $u1 = $this->usuario($this->departamento('Otro'), ['name' => 'Uno']);
        $u2 = $this->usuario($this->departamento('Otro2'), ['name' => 'Dos']);

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$u1->id, $u2->id],
        ]);

        Passport::actingAs($creador);
        $listadoInicial = $this->getJson('/api/open-issues?per_page=100');
        $filaInicial = collect($listadoInicial->json('data'))->firstWhere('id', $issue->id);

        $this->assertSame(3, $filaInicial['involucrados_count']); // creador + u1 + u2
        $this->assertSame(1, $filaInicial['actualizaciones_count']); // solo 'apertura'
        $this->assertLessThanOrEqual(5, count($filaInicial['involucrados_preview']));
        $this->assertNotEmpty($filaInicial['involucrados_preview']);

        // Avanzo el reloj para que el nuevo created_at de la actualización sea
        // estrictamente posterior (evita comparar timestamps iguales al segundo).
        $this->travel(2)->seconds();
        $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", ['texto' => 'Comentario nuevo'])->assertStatus(201);
        $this->travelBack();

        $listadoDespues = $this->getJson('/api/open-issues?per_page=100');
        $filaDespues = collect($listadoDespues->json('data'))->firstWhere('id', $issue->id);

        $this->assertSame(2, $filaDespues['actualizaciones_count']);
        $this->assertTrue(
            \Carbon\Carbon::parse($filaDespues['ultima_actualizacion_at'])->gt(\Carbon\Carbon::parse($filaDespues['created_at']))
        );
    }

    // =========================================================================
    // 26: Code review — #4 tope de involucrados evaluado ANTES de insertar
    // =========================================================================

    public function test_agregar_involucrados_que_supera_el_tope_no_inserta_nada(): void
    {
        // Tope bajo a propósito para no tener que crear 200+ usuarios en el test.
        config(['open_issues.max_involucrados_por_lote' => 2]);

        $deptoDestino = $this->departamento('Calidad');
        $deptoGrande = $this->departamento('Producción');
        $creador = $this->usuario($this->departamento('IT'));

        // 3 usuarios en el depto a expandir: supera el tope de 2.
        $this->usuario($deptoGrande);
        $this->usuario($deptoGrande);
        $this->usuario($deptoGrande);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);
        $this->assertSame(1, OpenIssueInvolucrado::where('issue_id', $issue->id)->count()); // solo el creador

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/involucrados", [
            'departamento_ids' => [$deptoGrande->id],
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors('involucrados');

        // Nada se insertó: la validación del tope corrió ANTES del loop de inserts,
        // no dejó altas parciales colgadas (revisión #4).
        $this->assertSame(1, OpenIssueInvolucrado::where('issue_id', $issue->id)->count());
    }

    // =========================================================================
    // 27: Code review — #6 'total' de /pendientes no se trunca por el limit(50)
    // =========================================================================

    public function test_pendientes_total_no_se_trunca_por_el_limit_50(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        // 52 issues abiertos donde $creador participa (como creador -> siempre involucrado):
        // más que el limit(50) del endpoint sin 'solo_total'.
        for ($i = 0; $i < 52; $i++) {
            $issue = OpenIssue::create([
                'titulo' => "Issue pendiente {$i}",
                'departamento_destino_id' => $deptoDestino->id,
                'creador_id' => $creador->id,
                'estado' => 'abierto',
                'prioridad' => 'media',
            ]);

            OpenIssueInvolucrado::create([
                'issue_id' => $issue->id,
                'user_id' => $creador->id,
                'origen' => OpenIssueInvolucrado::ORIGEN_CREADOR,
                'departamento_id' => null,
                'agregado_por_id' => $creador->id,
                'created_at' => now(),
            ]);
        }

        Passport::actingAs($creador);
        $resp = $this->getJson('/api/open-issues/pendientes');

        $resp->assertStatus(200);
        // Antes de la corrección 'total' era count() sobre las 50 filas ya limitadas: 50.
        // El total real (sin el limit) es 52.
        $this->assertSame(52, $resp->json('total'));
        $this->assertCount(50, $resp->json('issues'));
    }

    // =========================================================================
    // 28: Code review — #9 'ultima_actualizacion_at' en el detalle (show/store/etc)
    // =========================================================================

    public function test_detalle_incluye_ultima_actualizacion_at(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Passport::actingAs($creador);

        // Recién creado: solo tiene la actualización 'apertura', ultima_actualizacion_at
        // debe coincidir con esa (no con created_at del issue por casualidad: lo comparamos
        // contra la actualización real).
        $respInicial = $this->getJson("/api/open-issues/{$issue->id}");
        $respInicial->assertStatus(200);
        $this->assertNotNull($respInicial->json('ultima_actualizacion_at'));

        $apertura = OpenIssueActualizacion::where('issue_id', $issue->id)->where('tipo', 'apertura')->firstOrFail();
        $this->assertSame(
            $apertura->created_at->toJSON(),
            \Carbon\Carbon::parse($respInicial->json('ultima_actualizacion_at'))->toJSON()
        );

        $this->travel(2)->seconds();
        $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", ['texto' => 'Comentario nuevo'])->assertStatus(201);
        $this->travelBack();

        $respDespues = $this->getJson("/api/open-issues/{$issue->id}");
        $this->assertTrue(
            \Carbon\Carbon::parse($respDespues->json('ultima_actualizacion_at'))
                ->gt(\Carbon\Carbon::parse($respInicial->json('ultima_actualizacion_at')))
        );
    }

    // =========================================================================
    // 29: Code review — #2 N+1 de involucrados.usuario.departamento en el detalle
    // =========================================================================

    public function test_show_detalle_no_dispara_n_mas_1_de_departamento_por_involucrado(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoA = $this->departamento('IT');
        $deptoB = $this->departamento('RRHH');
        $creador = $this->usuario($deptoA);

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        $u1 = $this->usuario($deptoA);
        $u2 = $this->usuario($deptoB);
        $u3 = $this->usuario($deptoDestino);

        Passport::actingAs($creador);
        $this->postJson("/api/open-issues/{$issue->id}/involucrados", [
            'user_ids' => [$u1->id, $u2->id, $u3->id],
        ])->assertStatus(200);

        \DB::enableQueryLog();
        $resp = $this->getJson("/api/open-issues/{$issue->id}");
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $resp->assertStatus(200);

        // eagerLoadDetalle() toca la tabla 'departamentos' en, a lo sumo, 3 relaciones fijas
        // (departamentoDestino, involucrados.usuario.departamento, involucrados.departamento):
        // esa cantidad NO debe crecer con la cantidad de involucrados. Antes de la corrección
        // #2, 'involucrados.usuario.departamento' no estaba eager-cargada y detalle() disparaba
        // un SELECT de departamentos extra POR CADA involucrado al leer $inv->usuario->departamento.
        $queriesDepartamentos = collect($queries)->filter(
            fn ($q) => str_contains(strtolower($q['query']), 'departamentos')
        );
        $this->assertLessThanOrEqual(3, $queriesDepartamentos->count());

        $u1Data = collect($resp->json('involucrados'))->firstWhere('user_id', $u1->id);
        $u2Data = collect($resp->json('involucrados'))->firstWhere('user_id', $u2->id);

        $this->assertSame($deptoA->nombre, $u1Data['departamento_nombre']);
        $this->assertSame($deptoB->nombre, $u2Data['departamento_nombre']);
    }
}
