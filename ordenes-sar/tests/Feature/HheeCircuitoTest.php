<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\HheeHistorial;
use App\Models\HheeRolAprobacion;
use App\Models\Notificacion;
use App\Models\OrdenTrabajo;
use App\Models\SolicitudHhee;
use App\Models\User;
use App\Support\HheeAutorizacionException;
use App\Support\HheeFlujo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Test de Feature end-to-end del módulo HHEE (horas extras): circuito completo
 * GL crea -> firma nivel 1 -> firma nivel 2 -> aprobada -> horas reales -> cerrada,
 * más rechazo, contingencia, autorización negativa, validaciones 422, alcance del
 * listado y la regresión de notificaciones de OT (ver SolicitudHheeController,
 * App\Support\HheeFlujo/HheeAprobadores/AlcanceHhee/HheeNotificador).
 *
 * Entorno de DB: sqlite :memory: (ver phpunit.xml). Elegido en vez de apuntar a
 * SQL Server porque:
 *   - No se documentó ninguna base de test dedicada en SQL Server para este
 *     proyecto (a diferencia de la opción (b) sugerida).
 *   - RefreshDatabase + sqlsrv corre en transacción real contra la BD del
 *     .env (ordenes_sar), que es la de producción: usarla acá violaría "no
 *     tocar la base productiva".
 *   - Las migraciones HHEE (2026_08_25_*) corren en sqlite sin problema salvo
 *     la 000006 (T-SQL crudo sobre sys.foreign_keys para dropear FKs
 *     autogeneradas y ALTER COLUMN): se le agregó una rama no-sqlsrv que
 *     reconstruye la tabla `notificaciones` con Schema Builder (ver esa
 *     migración), sin tocar el camino sqlsrv original.
 *   - RefreshDatabase con sqlite :memory: es rápido y aislado por completo
 *     entre tests (nada de estado compartido/orden-dependiente).
 */
class HheeCircuitoTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers de armado
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

    /**
     * Asigna un rol de aprobación HHEE activo al usuario. $departamentoId=null
     * -> fila de alcance global (ver hhee_roles_aprobacion.departamento_id).
     */
    private function asignarRolHhee(User $user, string $rol, ?int $departamentoId = null): HheeRolAprobacion
    {
        return HheeRolAprobacion::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'departamento_id' => $departamentoId,
            'activo' => true,
        ]);
    }

    /**
     * Un detalle de empleado válido (turno 08:00-16:00 = 8hs, el backend
     * calcula el total solo), con overrides puntuales. Ya no lleva legajo,
     * cruza_medianoche ni desglose por tipo de hora (ver App\Support\HheeFlujo).
     */
    private function detalle(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Juan Pérez',
            'motivo' => 'Refuerzo de turno',
            'necesita_transporte' => false,
            'hora_desde' => '08:00',
            'hora_hasta' => '16:00',
        ], $overrides);
    }

    /**
     * $departamento ya NO viaja en el payload (departamento_id se ignora del
     * todo, ver App\Support\HheeFlujo): se mantiene como parámetro solo
     * porque casi todos los tests arman el usuario solicitante con ese mismo
     * $departamento, y el departamento REAL de la solicitud resultante sale
     * de auth()->user()->departamento_id (que en los tests coincide, porque
     * el solicitante que actúa fue creado con ese $departamento). En su lugar
     * va 'sector' (Corte/Costura/Mantenimiento/PC, ver config('hhee.sectores')).
     */
    private function payload(Departamento $departamento, array $detalles = null, array $overrides = []): array
    {
        return array_merge([
            'fecha_hhee' => now()->toDateString(),
            'sector' => 'Corte',
            'turno' => 'Turno Mañana',
            'observaciones' => 'Solicitud de prueba',
            'detalles' => $detalles ?? [$this->detalle()],
        ], $overrides);
    }

    // =========================================================================
    // Camino feliz completo
    // =========================================================================

    public function test_camino_feliz_completo_crear_editar_enviar_aprobar_n1_n2_horas_reales_cerrada(): void
    {
        $depto = $this->departamento('IT');
        $gl = $this->usuario($depto, ['name' => 'Ana GL']);
        $jefe = $this->usuario($depto, ['name' => 'Jose Jefe']);
        $rrhh = $this->usuario($this->departamento('RRHH'), ['name' => 'Rita RRHH']);

        $this->asignarRolHhee($jefe, 'jefe', $depto->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        // 1) Crear borrador
        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $this->payload($depto));
        $crear->assertStatus(201);
        $crear->assertJsonPath('estado', 'borrador');
        $id = $crear->json('id');

        // 2) Editar (sigue en borrador, agrego un segundo empleado)
        $editar = $this->putJson("/api/hhee/solicitudes/{$id}", $this->payload($depto, [
            $this->detalle(['nombre' => 'Juan Pérez']),
            $this->detalle(['nombre' => 'María López']),
        ]));
        $editar->assertStatus(200);
        $editar->assertJsonCount(2, 'detalles');

        // 3) Enviar
        $enviar = $this->postJson("/api/hhee/solicitudes/{$id}/enviar");
        $enviar->assertStatus(200);
        $enviar->assertJsonPath('estado', 'pendiente_nivel1');

        $solicitud = SolicitudHhee::find($id);
        $this->assertNotNull($solicitud->fecha_envio);
        $this->assertCount(2, $solicitud->aprobaciones);
        $this->assertSame('pendiente', $solicitud->aprobacionNivel(1)->first()->estado);
        $this->assertSame('pendiente', $solicitud->aprobacionNivel(2)->first()->estado);

        // Notificación al jefe: pendiente de su firma. La campana "fue enviada" al
        // solicitante NO se genera acá porque el actor de enviar() ES el propio
        // solicitante (destinatariosUnicos() nunca notifica al actor, ver
        // HheeNotificador::destinatariosUnicos()).
        $this->assertTrue($this->tieneNotificacionHhee($jefe, $id, 'pendiente de tu firma'));
        $this->assertFalse($this->tieneNotificacionHhee($gl, $id, 'fue enviada'));

        // 4) Aprobar nivel 1 (jefe legítimo del depto)
        Passport::actingAs($jefe);
        $aprobarN1 = $this->postJson("/api/hhee/solicitudes/{$id}/aprobar", ['comentario' => 'OK, adelante']);
        $aprobarN1->assertStatus(200);
        $aprobarN1->assertJsonPath('estado', 'pendiente_final');

        $solicitud->refresh();
        $ap1 = $solicitud->aprobacionNivel(1)->first();
        $this->assertSame('aprobada', $ap1->estado);
        $this->assertSame($jefe->id, $ap1->aprobador_id);
        $this->assertSame('jefe', $ap1->rol_aprobador);
        $this->assertFalse((bool) $ap1->es_contingencia);
        $this->assertNotNull($solicitud->fecha_aprobacion_nivel1);

        // Notificaciones: rrhh (pendiente firma nivel 2) + GL (avisado nivel1 ok)
        $this->assertTrue($this->tieneNotificacionHhee($rrhh, $id, 'pendiente de tu firma'));
        $this->assertTrue($this->tieneNotificacionHhee($gl, $id, 'aprobada en nivel 1'));

        // 5) Aprobar nivel final (rrhh)
        Passport::actingAs($rrhh);
        $aprobarN2 = $this->postJson("/api/hhee/solicitudes/{$id}/aprobar");
        $aprobarN2->assertStatus(200);
        $aprobarN2->assertJsonPath('estado', 'aprobada');

        $solicitud->refresh();
        $ap2 = $solicitud->aprobacionNivel(2)->first();
        $this->assertSame('aprobada', $ap2->estado);
        $this->assertSame($rrhh->id, $ap2->aprobador_id);
        $this->assertSame('rrhh', $ap2->rol_aprobador);
        $this->assertNotNull($solicitud->fecha_aprobacion_final);

        // Notificaciones: GL (aprobada, puede cargar reales) + jefe (avisado que se aprobó final)
        $this->assertTrue($this->tieneNotificacionHhee($gl, $id, 'Ya podés cargar las horas reales'));
        $this->assertTrue($this->tieneNotificacionHhee($jefe, $id, 'aprobada en forma final'));

        // 6) Cargar horas reales (solo el solicitante, y solo en 'aprobada')
        Passport::actingAs($gl);
        $detalleIds = $solicitud->detalles()->pluck('id');
        $cargar = $this->postJson("/api/hhee/solicitudes/{$id}/horas-reales", [
            'detalles' => $detalleIds->map(fn ($detalleId) => [
                'detalle_id' => $detalleId,
                'horas_reales' => 8,
                'fecha_realizacion' => now()->toDateString(),
            ])->all(),
        ]);
        $cargar->assertStatus(200);
        $cargar->assertJsonPath('estado', 'cerrada');

        $solicitud->refresh();
        $this->assertSame('16.00', (string) $solicitud->total_horas_reales);
        $this->assertSame(2, (int) $solicitud->total_empleados);
        $this->assertNotNull($solicitud->fecha_cierre);

        // Notificación a RRHH avisando cierre
        $this->assertTrue($this->tieneNotificacionHhee($rrhh, $id, 'quedó cerrada'));

        // Historial completo (append-only)
        $acciones = HheeHistorial::where('solicitud_id', $id)->orderBy('id')->pluck('accion')->all();
        $this->assertSame([
            'creada',
            'editada',
            'enviada',
            'aprobada_nivel1',
            'aprobada_final',
            'horas_reales_cargadas',
            'cerrada',
        ], $acciones);
    }

    private function tieneNotificacionHhee(User $destinatario, int $solicitudId, string $fragmentoMensaje): bool
    {
        return Notificacion::where('usuario_creador_id', $destinatario->id)
            ->where('tipo', 'hhee')
            ->where('solicitud_hhee_id', $solicitudId)
            ->where('mensaje', 'like', "%{$fragmentoMensaje}%")
            ->exists();
    }

    // =========================================================================
    // Firma implícita de nivel 1 (feedback de usuario post-release): si quien
    // envía ya es aprobador LEGÍTIMO de nivel 1 para el depto de la solicitud,
    // HheeFlujo::enviar() la autofirma en la misma transacción y pasa directo
    // a pendiente_final. Caso real: un gerente de IT carga la solicitud -> ya
    // solo falta la firma final de gerencia general.
    // =========================================================================

    public function test_enviar_con_solicitante_jefe_de_su_propio_depto_firma_nivel1_implicitamente(): void
    {
        $depto = $this->departamento('IT');
        $gerenteIt = $this->usuario($depto, ['name' => 'Gerente IT']);
        $rrhh = $this->usuario($this->departamento('RRHH'), ['name' => 'Rita RRHH']);
        $this->asignarRolHhee($gerenteIt, 'jefe', $depto->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $solicitud = $this->crearYEnviar($gerenteIt, $depto);

        // Pasa DIRECTO a pendiente_final (se saltea "pendiente_nivel1" a los
        // ojos de quien consulta después de enviar).
        $solicitud->refresh();
        $this->assertSame('pendiente_final', $solicitud->estado);
        $this->assertNotNull($solicitud->fecha_aprobacion_nivel1);

        // La aprobación de nivel 1 queda firmada por el propio solicitante,
        // SIN contingencia, con su rol legítimo y el comentario fijo.
        $ap1 = $solicitud->aprobacionNivel(1)->first();
        $this->assertSame('aprobada', $ap1->estado);
        $this->assertSame($gerenteIt->id, $ap1->aprobador_id);
        $this->assertSame('jefe', $ap1->rol_aprobador);
        $this->assertFalse((bool) $ap1->es_contingencia);
        $this->assertSame(HheeFlujo::COMENTARIO_FIRMA_IMPLICITA, $ap1->comentario);
        $this->assertNotNull($ap1->firmado_at);

        // Nivel 2 sigue genuinamente pendiente: la regla NO es autoaprobación
        // del circuito completo.
        $ap2 = $solicitud->aprobacionNivel(2)->first();
        $this->assertSame('pendiente', $ap2->estado);
        $this->assertNull($ap2->aprobador_id);

        // Historial: 'enviada' Y 'aprobada_nivel1' (con el comentario de firma
        // implícita), en ese orden.
        $historial = HheeHistorial::where('solicitud_id', $solicitud->id)->orderBy('id')->get();
        $this->assertSame(['creada', 'enviada', 'aprobada_nivel1'], $historial->pluck('accion')->all());

        $historialAprobadaNivel1 = $historial->firstWhere('accion', 'aprobada_nivel1');
        $this->assertSame(HheeFlujo::COMENTARIO_FIRMA_IMPLICITA, $historialAprobadaNivel1->comentario);
        $this->assertFalse((bool) $historialAprobadaNivel1->es_contingencia);
        $this->assertSame('pendiente_nivel1', $historialAprobadaNivel1->estado_anterior);
        $this->assertSame('pendiente_final', $historialAprobadaNivel1->estado_nuevo);

        // Se notifica a los aprobadores de NIVEL 2 (rrhh), no hay nadie de
        // nivel 1 a quien avisar (nunca hubo nada pendiente en ese nivel).
        $this->assertTrue($this->tieneNotificacionHhee($rrhh, $solicitud->id, 'pendiente de tu firma'));

        // El nivel 2 sigue exigiendo la firma de OTRA persona: el propio
        // gerente de IT no puede autoaprobarlo.
        Passport::actingAs($gerenteIt);
        $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar")->assertStatus(403);

        // Y rrhh sí puede aprobarlo, cerrando el circuito en 2 pasos en vez de 3.
        Passport::actingAs($rrhh);
        $aprobar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar");
        $aprobar->assertStatus(200);
        $aprobar->assertJsonPath('estado', 'aprobada');
    }

    public function test_enviar_con_rol_nivel1_escopeado_a_otro_departamento_no_dispara_firma_implicita(): void
    {
        // El departamento de la solicitud SIEMPRE es el del solicitante (ver
        // App\Support\HheeFlujo): ya no se puede "elegir" a mano un
        // departamento distinto en el payload (eso es justamente lo que
        // eliminó la posibilidad de desviar el circuito a otro jefe). Para
        // simular el mismatch departamental acá hay que hacerlo por el lado
        // del ROL: el usuario pertenece a $deptoPropio (así que su solicitud
        // queda con ese departamento), pero su rol 'jefe' de nivel 1 está
        // escopeado a OTRO departamento -> no matchea, no hay firma implícita
        // (mismo alcance departamental que rige el resto del módulo, ver
        // HheeAprobadores::rolLegitimoParaNivel()).
        $deptoPropio = $this->departamento('Producción');
        $otroDepto = $this->departamento('IT');
        $jefeProduccion = $this->usuario($deptoPropio);
        $this->asignarRolHhee($jefeProduccion, 'jefe', $otroDepto->id);

        $solicitud = $this->crearYEnviar($jefeProduccion, $deptoPropio);

        $this->assertSame($deptoPropio->id, $solicitud->fresh()->departamento_id);
        $this->assertSame('pendiente_nivel1', $solicitud->fresh()->estado);
        $this->assertNull($solicitud->fresh()->fecha_aprobacion_nivel1);
    }

    public function test_enviar_con_solicitante_de_contingencia_no_dispara_firma_implicita(): void
    {
        // La contingencia NUNCA dispara la firma implícita, solo un rol
        // LEGÍTIMO de nivel 1 (ver HheeAprobadores::rolLegitimoParaNivel(),
        // que ignora contingencia a propósito).
        $depto = $this->departamento();
        $usuarioContingencia = $this->usuario($depto);
        $this->asignarRolHhee($usuarioContingencia, 'contingencia', null);

        $solicitud = $this->crearYEnviar($usuarioContingencia, $depto);

        $this->assertSame('pendiente_nivel1', $solicitud->fresh()->estado);
        $ap1 = $solicitud->fresh()->aprobacionNivel(1)->first();
        $this->assertSame('pendiente', $ap1->estado);
    }

    public function test_enviar_con_gl_comun_sigue_quedando_pendiente_nivel1(): void
    {
        // Regresión explícita: un solicitante SIN ningún rol de aprobación
        // (el caso normal, un GL/team_member cualquiera) sigue el flujo de
        // siempre, sin ninguna firma implícita.
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);

        $solicitud = $this->crearYEnviar($gl, $depto);

        $this->assertSame('pendiente_nivel1', $solicitud->fresh()->estado);
        $ap1 = $solicitud->fresh()->aprobacionNivel(1)->first();
        $this->assertSame('pendiente', $ap1->estado);
        $this->assertNull($ap1->aprobador_id);

        $historial = HheeHistorial::where('solicitud_id', $solicitud->id)->orderBy('id')->pluck('accion')->all();
        $this->assertSame(['creada', 'enviada'], $historial);
    }

    // =========================================================================
    // Rechazo en N1 y en N2
    // =========================================================================

    public function test_rechazo_en_nivel1_deja_la_aprobacion_de_nivel2_pendiente_sin_firmar(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);

        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($jefe);
        $rechazar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/rechazar", [
            'motivo' => 'Falta autorización del área',
        ]);
        $rechazar->assertStatus(200);
        $rechazar->assertJsonPath('estado', 'rechazada');

        $solicitud->refresh();
        $this->assertSame('rechazada', $solicitud->estado);
        $this->assertSame($jefe->id, $solicitud->rechazado_por_id);
        $this->assertSame('Falta autorización del área', $solicitud->motivo_rechazo);

        $ap1 = $solicitud->aprobacionNivel(1)->first();
        $this->assertSame('rechazada', $ap1->estado);
        $this->assertSame($jefe->id, $ap1->aprobador_id);

        $ap2 = $solicitud->aprobacionNivel(2)->first();
        $this->assertSame('pendiente', $ap2->estado);
        $this->assertNull($ap2->aprobador_id);

        // El show() incluye quién rechazó (fix de eager-load, ver rechazadoPor()).
        Passport::actingAs($gl);
        $show = $this->getJson("/api/hhee/solicitudes/{$solicitud->id}");
        $show->assertStatus(200);
        $show->assertJsonPath('rechazado_por.id', $jefe->id);
        $show->assertJsonPath('rechazado_por.name', $jefe->name);
    }

    public function test_rechazo_en_nivel2_no_toca_la_firma_ya_aprobada_de_nivel1(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($jefe);
        $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar")->assertStatus(200);

        Passport::actingAs($rrhh);
        $rechazar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/rechazar", [
            'motivo' => 'No corresponde el pago',
        ]);
        $rechazar->assertStatus(200);
        $rechazar->assertJsonPath('estado', 'rechazada');

        $solicitud->refresh();
        $ap1 = $solicitud->aprobacionNivel(1)->first();
        $this->assertSame('aprobada', $ap1->estado);
        $this->assertSame($jefe->id, $ap1->aprobador_id);

        $ap2 = $solicitud->aprobacionNivel(2)->first();
        $this->assertSame('rechazada', $ap2->estado);
        $this->assertSame($rrhh->id, $ap2->aprobador_id);
    }

    public function test_rechazar_sin_motivo_da_422(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);

        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($jefe);
        $rechazar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/rechazar", []);
        $rechazar->assertStatus(422);
        $rechazar->assertJsonValidationErrors(['motivo']);
    }

    // =========================================================================
    // Contingencia
    // =========================================================================

    public function test_firma_por_contingencia_en_nivel1_queda_marcada_es_contingencia(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $contingencia = $this->usuario($this->departamento('Contingencia'));
        $this->asignarRolHhee($contingencia, 'contingencia', null);

        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($contingencia);
        $aprobar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar");
        $aprobar->assertStatus(200);

        $ap1 = $solicitud->fresh()->aprobacionNivel(1)->first();
        $this->assertTrue((bool) $ap1->es_contingencia);
        $this->assertSame('contingencia', $ap1->rol_aprobador);
        $this->assertSame($contingencia->id, $ap1->aprobador_id);
    }

    public function test_firma_por_contingencia_en_nivel2_queda_marcada_es_contingencia(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $contingencia = $this->usuario($this->departamento('Contingencia'));
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);
        $this->asignarRolHhee($contingencia, 'contingencia', null);

        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($jefe);
        $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar")->assertStatus(200);

        Passport::actingAs($contingencia);
        $aprobar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar");
        $aprobar->assertStatus(200);
        $aprobar->assertJsonPath('estado', 'aprobada');

        $ap2 = $solicitud->fresh()->aprobacionNivel(2)->first();
        $this->assertTrue((bool) $ap2->es_contingencia);
        $this->assertSame('contingencia', $ap2->rol_aprobador);
    }

    public function test_usuario_con_rol_legitimo_y_contingencia_firma_como_legitimo_no_como_contingencia(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefeConContingencia = $this->usuario($depto);
        $this->asignarRolHhee($jefeConContingencia, 'jefe', $depto->id);
        $this->asignarRolHhee($jefeConContingencia, 'contingencia', null);

        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($jefeConContingencia);
        $aprobar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar");
        $aprobar->assertStatus(200);

        $ap1 = $solicitud->fresh()->aprobacionNivel(1)->first();
        $this->assertFalse((bool) $ap1->es_contingencia);
        $this->assertSame('jefe', $ap1->rol_aprobador);
    }

    // =========================================================================
    // Autorización negativa
    // =========================================================================

    public function test_usuario_sin_ningun_rol_hhee_no_puede_aprobar(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $intruso = $this->usuario($depto);

        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($intruso);
        $aprobar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar");
        $aprobar->assertStatus(403);
    }

    public function test_aprobador_nivel1_de_otro_departamento_no_puede_firmar(): void
    {
        $deptoA = $this->departamento('A');
        $deptoB = $this->departamento('B');
        $gl = $this->usuario($deptoA);
        $jefeDeptoB = $this->usuario($deptoB);
        $this->asignarRolHhee($jefeDeptoB, 'jefe', $deptoB->id);

        $solicitud = $this->crearYEnviar($gl, $deptoA);

        Passport::actingAs($jefeDeptoB);
        $aprobar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar");
        $aprobar->assertStatus(403);
    }

    public function test_el_solicitante_no_puede_autoaprobar_su_propia_solicitud(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        // El propio GL también es "jefe" del depto (para descartar que el 403
        // sea por falta de rol y no por autoaprobación).
        $this->asignarRolHhee($gl, 'jefe', $depto->id);

        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($gl);
        $aprobar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar");
        $aprobar->assertStatus(403);
    }

    public function test_no_se_puede_aprobar_nivel2_mientras_la_solicitud_esta_pendiente_de_nivel1(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        // Nadie firmó nivel 1 todavía: la solicitud sigue en pendiente_nivel1.
        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($rrhh);
        $aprobar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar");
        $aprobar->assertStatus(403);

        $this->assertSame('pendiente_nivel1', $solicitud->fresh()->estado);
    }

    public function test_no_se_puede_editar_una_solicitud_ya_enviada(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($gl);
        $editar = $this->putJson("/api/hhee/solicitudes/{$solicitud->id}", $this->payload($depto));
        $editar->assertStatus(422);
        $editar->assertJsonValidationErrors(['estado']);
    }

    public function test_solo_el_solicitante_puede_anular(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $otro = $this->usuario($depto);
        $solicitud = $this->crearYEnviar($gl, $depto);

        Passport::actingAs($otro);
        $anular = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/anular");
        $anular->assertStatus(403);

        Passport::actingAs($gl);
        $anular = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/anular");
        $anular->assertStatus(200);
        $anular->assertJsonPath('estado', 'anulada');
    }

    public function test_solo_el_solicitante_y_solo_en_aprobada_puede_cargar_horas_reales(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $solicitud = $this->crearYEnviar($gl, $depto);
        $detalleId = $solicitud->detalles()->first()->id;
        $payloadReales = [
            'detalles' => [[
                'detalle_id' => $detalleId,
                'horas_reales' => 8,
                'fecha_realizacion' => now()->toDateString(),
            ]],
        ];

        // Todavía pendiente_nivel1: ni siquiera el solicitante puede cargar reales.
        Passport::actingAs($gl);
        $cargar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/horas-reales", $payloadReales);
        $cargar->assertStatus(422);
        $cargar->assertJsonValidationErrors(['estado']);

        Passport::actingAs($jefe);
        $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar")->assertStatus(200);
        Passport::actingAs($rrhh);
        $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar")->assertStatus(200);

        // Ya está aprobada: otro usuario (no el solicitante) no puede cargar reales.
        Passport::actingAs($jefe);
        $cargar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/horas-reales", $payloadReales);
        $cargar->assertStatus(403);

        // El solicitante sí puede.
        Passport::actingAs($gl);
        $cargar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/horas-reales", $payloadReales);
        $cargar->assertStatus(200);
        $cargar->assertJsonPath('estado', 'cerrada');
    }

    // =========================================================================
    // Validaciones 422
    // =========================================================================

    public function test_store_sin_detalles_da_422(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, []));
        $crear->assertStatus(422);
        $crear->assertJsonValidationErrors(['detalles']);
    }

    public function test_store_con_hora_desde_igual_a_hora_hasta_da_422(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, [
            $this->detalle(['hora_desde' => '08:00', 'hora_hasta' => '08:00']),
        ]));
        $crear->assertStatus(422);
        $crear->assertJsonValidationErrors(['detalles.0']);
    }

    public function test_store_con_turno_que_cruza_medianoche_calcula_las_horas_automaticamente(): void
    {
        // 22:00 -> 02:00 = 4hs, sin mandar ningún flag de cruce.
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, [
            $this->detalle(['hora_desde' => '22:00', 'hora_hasta' => '02:00']),
        ]));
        $crear->assertStatus(201);
        $id = $crear->json('id');

        $detalle = SolicitudHhee::findOrFail($id)->detalles()->first();
        $this->assertSame('4.00', (string) $detalle->horas_teoricas);
        $this->assertTrue((bool) $detalle->cruza_medianoche);
    }

    public function test_store_que_supera_el_tope_de_horas_por_empleado_da_422(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, [
            $this->detalle([
                'hora_desde' => '08:00',
                'hora_hasta' => '23:00', // 15hs, supera el tope default de 12
            ]),
        ]));
        $crear->assertStatus(422);
        $crear->assertJsonValidationErrors(['detalles.0']);
    }

    public function test_store_con_necesita_transporte_sin_localidad_da_422(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, [
            $this->detalle(['necesita_transporte' => true]), // sin 'localidad'
        ]));
        $crear->assertStatus(422);
        $crear->assertJsonValidationErrors(['detalles.0.localidad']);
    }

    public function test_store_con_sector_invalido_da_422(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, null, ['sector' => 'Depósito']));
        $crear->assertStatus(422);
        $crear->assertJsonValidationErrors(['sector']);
    }

    public function test_store_sin_sector_da_422(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        $payload = $this->payload($depto);
        unset($payload['sector']);

        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $payload);
        $crear->assertStatus(422);
        $crear->assertJsonValidationErrors(['sector']);
    }

    public function test_el_departamento_de_la_solicitud_es_siempre_el_del_solicitante_aunque_el_payload_traiga_otro(): void
    {
        $deptoSolicitante = $this->departamento('Producción');
        $otroDepto = $this->departamento('IT');
        $gl = $this->usuario($deptoSolicitante);

        Passport::actingAs($gl);
        // Manda 'departamento_id' de OTRO departamento en el payload: ya no
        // se acepta (no hay regla de validación para ese campo), así que
        // Laravel lo descarta sin romper con 422 por "campo extra", y
        // App\Support\HheeFlujo lo ignora del todo -> el departamento real de
        // la solicitud es SIEMPRE el del solicitante logueado.
        $crear = $this->postJson('/api/hhee/solicitudes', array_merge(
            $this->payload($deptoSolicitante),
            ['departamento_id' => $otroDepto->id]
        ));

        $crear->assertStatus(201);
        $id = $crear->json('id');

        $solicitud = SolicitudHhee::findOrFail($id);
        $this->assertSame($deptoSolicitante->id, $solicitud->departamento_id);
        $this->assertNotSame($otroDepto->id, $solicitud->departamento_id);

        // update() también lo ignora, por más que el body lo traiga.
        $tercerDepto = $this->departamento('Calidad');
        $editar = $this->putJson("/api/hhee/solicitudes/{$id}", array_merge(
            $this->payload($deptoSolicitante),
            ['departamento_id' => $tercerDepto->id]
        ));
        $editar->assertStatus(200);
        $this->assertSame($deptoSolicitante->id, $solicitud->fresh()->departamento_id);
    }

    public function test_store_persiste_el_user_id_opcional_del_detalle_para_el_buscador_de_empleados(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $empleado = $this->usuario($depto, ['name' => 'Empleado Con Usuario']);

        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, [
            $this->detalle(['nombre' => $empleado->name, 'user_id' => $empleado->id]),
            $this->detalle(['nombre' => 'Operario Sin Usuario']), // sin user_id
        ]));
        $crear->assertStatus(201);
        $id = $crear->json('id');

        $solicitud = SolicitudHhee::findOrFail($id);
        $detalleConUsuario = $solicitud->detalles()->where('nombre', $empleado->name)->first();
        $detalleSinUsuario = $solicitud->detalles()->where('nombre', 'Operario Sin Usuario')->first();

        $this->assertSame($empleado->id, $detalleConUsuario->user_id);
        $this->assertNull($detalleSinUsuario->user_id);

        // update() también lo persiste (reemplazo completo de detalles).
        $otroEmpleado = $this->usuario($depto, ['name' => 'Otro Empleado']);
        $editar = $this->putJson("/api/hhee/solicitudes/{$id}", $this->payload($depto, [
            $this->detalle(['nombre' => $otroEmpleado->name, 'user_id' => $otroEmpleado->id]),
        ]));
        $editar->assertStatus(200);

        $detalleEditado = $solicitud->fresh()->detalles()->first();
        $this->assertSame($otroEmpleado->id, $detalleEditado->user_id);
    }

    public function test_store_con_user_id_inexistente_da_422(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $crear = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, [
            $this->detalle(['user_id' => 999999]),
        ]));
        $crear->assertStatus(422);
        $crear->assertJsonValidationErrors(['detalles.0.user_id']);
    }

    public function test_catalogos_incluye_usuarios_para_el_buscador_de_empleados(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto, ['name' => 'Zzz Usuario']);
        $otro = $this->usuario($depto, ['name' => 'Aaa Usuario']);

        Passport::actingAs($gl);
        $catalogos = $this->getJson('/api/hhee/catalogos');
        $catalogos->assertStatus(200);

        $usuarios = collect($catalogos->json('usuarios'));
        $this->assertGreaterThanOrEqual(2, $usuarios->count());

        $entradaGl = $usuarios->firstWhere('id', $gl->id);
        $this->assertNotNull($entradaGl);
        $this->assertSame($gl->name, $entradaGl['name']);
        $this->assertSame($depto->id, $entradaGl['departamento_id']);

        // Orden alfabético por nombre: 'Aaa Usuario' antes que 'Zzz Usuario'.
        $indiceAaa = $usuarios->search(fn ($u) => $u['id'] === $otro->id);
        $indiceZzz = $usuarios->search(fn ($u) => $u['id'] === $gl->id);
        $this->assertLessThan($indiceZzz, $indiceAaa);
    }

    public function test_catalogos_incluye_los_4_sectores_fijos(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $catalogos = $this->getJson('/api/hhee/catalogos');
        $catalogos->assertStatus(200);
        $catalogos->assertJsonPath('sectores', ['Corte', 'Costura', 'Mantenimiento', 'PC']);
    }

    // =========================================================================
    // Alcance del listado (App\Support\AlcanceHhee)
    // =========================================================================

    public function test_alcance_del_listado_gl_ve_solo_las_suyas_aprobador_n1_ve_su_depto_nivel2_ve_todas(): void
    {
        $deptoA = $this->departamento('A');
        $deptoB = $this->departamento('B');

        $glA = $this->usuario($deptoA, ['name' => 'GL A']);
        $glB = $this->usuario($deptoB, ['name' => 'GL B']);
        $jefeA = $this->usuario($deptoA, ['name' => 'Jefe A']);
        $rrhh = $this->usuario($this->departamento('RRHH'), ['name' => 'RRHH']);
        $this->asignarRolHhee($jefeA, 'jefe', $deptoA->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        Passport::actingAs($glA);
        $borradorA = $this->postJson('/api/hhee/solicitudes', $this->payload($deptoA))->json('id');
        $enviadaA = $this->crearYEnviar($glA, $deptoA)->id;

        $enviadaB = $this->crearYEnviar($glB, $deptoB)->id;

        // GL A: ve sus 2 propias (borrador + enviada), no la de GL B.
        Passport::actingAs($glA);
        $idsGlA = $this->getJson('/api/hhee/solicitudes?per_page=100')->json('data.*.id');
        $this->assertEqualsCanonicalizing([$borradorA, $enviadaA], $idsGlA);

        // Jefe A: ve la enviada de su depto, NO el borrador ajeno, NO la de depto B.
        Passport::actingAs($jefeA);
        $idsJefeA = $this->getJson('/api/hhee/solicitudes?per_page=100')->json('data.*.id');
        $this->assertEqualsCanonicalizing([$enviadaA], $idsJefeA);

        // RRHH (nivel 2, alcance global): ve TODAS las no-borrador.
        Passport::actingAs($rrhh);
        $idsRrhh = $this->getJson('/api/hhee/solicitudes?per_page=100')->json('data.*.id');
        $this->assertEqualsCanonicalizing([$enviadaA, $enviadaB], $idsRrhh);
    }

    public function test_index_incluye_sector_en_la_respuesta_y_permite_filtrar_por_sector(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $idCorte = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, null, ['sector' => 'Corte']))->json('id');
        $this->postJson('/api/hhee/solicitudes', $this->payload($depto, null, ['sector' => 'Costura']))->json('id');

        $listado = $this->getJson('/api/hhee/solicitudes?per_page=100');
        $listado->assertStatus(200);
        $solicitudCorte = collect($listado->json('data'))->firstWhere('id', $idCorte);
        $this->assertSame('Corte', $solicitudCorte['sector']);

        $filtrado = $this->getJson('/api/hhee/solicitudes?sector=Corte&per_page=100');
        $filtrado->assertStatus(200);
        $this->assertEqualsCanonicalizing([$idCorte], collect($filtrado->json('data'))->pluck('id')->all());

        $filtroInvalido = $this->getJson('/api/hhee/solicitudes?sector=NoExiste');
        $filtroInvalido->assertStatus(422);
        $filtroInvalido->assertJsonValidationErrors(['sector']);
    }

    public function test_show_incluye_sector(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);

        Passport::actingAs($gl);
        $id = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, null, ['sector' => 'PC']))->json('id');

        $show = $this->getJson("/api/hhee/solicitudes/{$id}");
        $show->assertStatus(200);
        $show->assertJsonPath('sector', 'PC');
    }

    // =========================================================================
    // Regresión OT: NotificacionesController::index() sigue sirviendo ambos tipos
    // =========================================================================

    public function test_notificaciones_index_devuelve_tanto_las_de_ot_como_las_de_hhee(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $otroUsuario = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);

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

        // El actor de "aprobar nivel 1" es el jefe (no el propio GL), así que la
        // campana SÍ le llega al GL (ver HheeNotificador::destinatariosUnicos()).
        $solicitud = $this->crearYEnviar($gl, $depto);
        Passport::actingAs($jefe);
        $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar")->assertStatus(200);

        Passport::actingAs($gl);
        $response = $this->getJson('/api/notificaciones');
        $response->assertStatus(200);

        $notificaciones = collect($response->json());
        $this->assertCount(2, $notificaciones);

        $notifOt = $notificaciones->firstWhere('tipo', 'ot');
        $this->assertNotNull($notifOt);
        $this->assertStringContainsString('ha cambiado el estado de la orden de trabajo a aprobada', $notifOt['detalle']);

        $notifHhee = $notificaciones->firstWhere('tipo', 'hhee');
        $this->assertNotNull($notifHhee);
        $this->assertSame($solicitud->id, $notifHhee['solicitud_hhee_id']);
        $this->assertStringContainsString("Solicitud HHEE #{$solicitud->id}", $notifHhee['detalle']);
        $this->assertStringContainsString('aprobada en nivel 1', $notifHhee['detalle']);
    }

    // =========================================================================
    // Fix de code review #1: horas reales — tope por empleado + cobertura
    // completa de detalle_id (antes: aceptaba 96hs reales sin límite, y un
    // subconjunto de detalles dejaba al resto en 0 para siempre porque la
    // solicitud queda 'cerrada', que es terminal).
    // =========================================================================

    public function test_cargar_horas_reales_que_supera_el_tope_por_empleado_da_422(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $solicitud = $this->crearYEnviar($gl, $depto);
        Passport::actingAs($jefe);
        $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar")->assertStatus(200);
        Passport::actingAs($rrhh);
        $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar")->assertStatus(200);

        $detalleId = $solicitud->detalles()->first()->id;

        Passport::actingAs($gl);
        $cargar = $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/horas-reales", [
            'detalles' => [[
                'detalle_id' => $detalleId,
                // 20hs: por encima del tope default (12), pero dentro del
                // max:24 de sanidad del controller (para que el 422 salga de
                // la validación de negocio de HheeFlujo, no de la forma del
                // payload).
                'horas_reales' => 20,
                'fecha_realizacion' => now()->toDateString(),
            ]],
        ]);

        $cargar->assertStatus(422);
        $cargar->assertJsonValidationErrors(['detalles.0']);

        // No debe haber cerrado la solicitud (la validación aborta ANTES de
        // mutar nada): sigue 'aprobada', no quedó en un estado terminal con
        // datos corruptos.
        $this->assertSame('aprobada', $solicitud->fresh()->estado);
    }

    public function test_cargar_horas_reales_con_subconjunto_de_detalles_da_422_por_cobertura_incompleta(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        Passport::actingAs($gl);
        $id = $this->postJson('/api/hhee/solicitudes', $this->payload($depto, [
            $this->detalle(['nombre' => 'Juan Pérez']),
            $this->detalle(['nombre' => 'María López']),
        ]))->json('id');
        $this->postJson("/api/hhee/solicitudes/{$id}/enviar")->assertStatus(200);

        $solicitud = SolicitudHhee::findOrFail($id);
        Passport::actingAs($jefe);
        $this->postJson("/api/hhee/solicitudes/{$id}/aprobar")->assertStatus(200);
        Passport::actingAs($rrhh);
        $this->postJson("/api/hhee/solicitudes/{$id}/aprobar")->assertStatus(200);

        $primerDetalleId = $solicitud->detalles()->orderBy('id')->first()->id;

        // Manda SOLO el primer empleado: el segundo queda sin cubrir.
        Passport::actingAs($gl);
        $cargar = $this->postJson("/api/hhee/solicitudes/{$id}/horas-reales", [
            'detalles' => [[
                'detalle_id' => $primerDetalleId,
                'horas_reales' => 8,
                'fecha_realizacion' => now()->toDateString(),
            ]],
        ]);

        $cargar->assertStatus(422);
        $cargar->assertJsonValidationErrors(['detalles']);

        // Sigue 'aprobada': no se cerró con el segundo empleado en 0 para siempre.
        $this->assertSame('aprobada', $solicitud->fresh()->estado);
    }

    // =========================================================================
    // Fix de code review #2: lockForUpdate() + re-chequeo de estado DENTRO de
    // la transacción (antes: nivelPendiente() se resolvía sobre el objeto
    // pasado por el caller, no sobre la fila recién bloqueada, así que un
    // segundo firmante "concurrente" del MISMO nivel pisaba la firma del
    // primero, o una anulación/rechazo concurrente con una aprobación podía
    // dejar datos inconsistentes).
    // =========================================================================

    public function test_aprobar_re_resuelve_el_nivel_pendiente_bajo_lock_y_no_pisa_una_firma_ya_resuelta(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe1 = $this->usuario($depto, ['name' => 'Jefe Uno']);
        $jefe2 = $this->usuario($depto, ['name' => 'Jefe Dos']);
        $this->asignarRolHhee($jefe1, 'jefe', $depto->id);
        $this->asignarRolHhee($jefe2, 'jefe', $depto->id);

        $solicitud = $this->crearYEnviar($gl, $depto);

        // Snapshot "stale": lo que un segundo request concurrente habría
        // leído ANTES de que el primero terminara (todavía pendiente_nivel1
        // en memoria).
        $staleParaJefe2 = SolicitudHhee::find($solicitud->id);

        // Jefe 1 gana la carrera y aprueba nivel 1 (usando su propia copia).
        HheeFlujo::aprobar($solicitud->fresh(), $jefe1, null);
        $this->assertSame('pendiente_final', $solicitud->fresh()->estado);

        // Jefe 2 llega con el objeto STALE (estado en memoria = pendiente_nivel1).
        // Sin el fix, HheeFlujo resolvía el nivel a partir de ESE objeto (nivel
        // 1) y pisaba la fila de aprobación de nivel 1 ya firmada por Jefe 1.
        // Con el fix, vuelve a leer la solicitud bajo lock (estado real =
        // pendiente_final -> nivel 2) y, como Jefe 2 no tiene rol de nivel
        // final, se rechaza con 403 en vez de corromper la firma de nivel 1.
        $this->expectException(HheeAutorizacionException::class);
        HheeFlujo::aprobar($staleParaJefe2, $jefe2, null);
    }

    public function test_aprobar_re_resuelto_como_nivel_final_no_pisa_la_firma_de_nivel1_y_confirma_estado_real(): void
    {
        // Variante en la que, tras el re-chequeo, el "segundo" firmante SÍ
        // tiene permiso (rrhh) para el nivel que quedó realmente pendiente:
        // el resultado debe ser una aprobación final legítima, sin tocar la
        // fila de nivel 1 que ya había firmado el jefe.
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $solicitud = $this->crearYEnviar($gl, $depto);
        $staleParaRrhh = SolicitudHhee::find($solicitud->id);

        HheeFlujo::aprobar($solicitud->fresh(), $jefe, 'ok nivel 1');

        $resultado = HheeFlujo::aprobar($staleParaRrhh, $rrhh, 'ok nivel final');
        $this->assertSame('aprobada', $resultado->estado);

        $ap1 = $resultado->aprobacionNivel(1)->first();
        $this->assertSame($jefe->id, $ap1->aprobador_id);
        $this->assertSame('aprobada', $ap1->estado);
    }

    public function test_rechazar_re_chequea_el_estado_bloqueado_y_no_el_objeto_stale(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);

        $solicitud = $this->crearYEnviar($gl, $depto);
        $staleAntesDeAnular = SolicitudHhee::find($solicitud->id);

        // El solicitante anula la solicitud "primero" (gana la carrera).
        HheeFlujo::anular($solicitud->fresh(), $gl);
        $this->assertSame('anulada', $solicitud->fresh()->estado);

        // El jefe llega con un objeto stale que todavía la ve pendiente_nivel1:
        // sin el re-chequeo dentro de la transacción, rechazar() marcaría
        // igual hhee_aprobaciones.nivel=1 como 'rechazada' sobre una solicitud
        // ya anulada (terminal). Con el fix, se aborta con 422.
        $this->expectException(ValidationException::class);
        HheeFlujo::rechazar($staleAntesDeAnular, $jefe, 'Motivo tardío');
    }

    // =========================================================================
    // Fix de code review #3: GET /pendientes (badge) solo debe mostrar
    // solicitudes que el usuario REALMENTE puede firmar (antes: un rol de
    // nivel final -alcance "global"- también veía las pendiente_nivel1 en su
    // badge, y un aprobador veía su PROPIA solicitud pendiente aunque la
    // autoaprobación esté bloqueada por config).
    // =========================================================================

    public function test_pendientes_solo_muestra_los_niveles_que_el_usuario_puede_firmar_y_excluye_autoaprobacion(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        // A: pendiente_nivel1 (recién enviada).
        $solicitudA = $this->crearYEnviar($gl, $depto);

        // B: pendiente_final (el jefe ya aprobó nivel 1).
        $solicitudB = $this->crearYEnviar($gl, $depto);
        Passport::actingAs($jefe);
        $this->postJson("/api/hhee/solicitudes/{$solicitudB->id}/aprobar")->assertStatus(200);

        // C: propia del jefe. Como el jefe es aprobador LEGÍTIMO de nivel 1 de
        // su propio depto, HheeFlujo::enviar() la autofirma implícitamente
        // (ver test dedicado más abajo): queda pendiente_final, NO
        // pendiente_nivel1. Sirve igual para probar la exclusión por
        // autoaprobación, pero ahora en cabeza del jefe sobre el nivel FINAL
        // (no tiene ese rol, así que ya quedaría afuera por eso solo; lo
        // relevante acá es que C sí debe aparecer en el pendientes de RRHH,
        // que no es el solicitante).
        $solicitudC = $this->crearYEnviar($jefe, $depto);
        $this->assertSame('pendiente_final', $solicitudC->fresh()->estado);

        // El jefe: ve A (nivel1 ajena de su depto) pero NO B ni C (nivel
        // final, no tiene ese rol; y C además es propia).
        Passport::actingAs($jefe);
        $pendJefe = $this->getJson('/api/hhee/pendientes');
        $pendJefe->assertStatus(200);
        $idsJefe = collect($pendJefe->json('solicitudes'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$solicitudA->id], $idsJefe);
        $this->assertSame(1, $pendJefe->json('total'));

        // RRHH (nivel final): ve B y C (ambas pendiente_final) pero NO A (no
        // tiene rol de nivel 1).
        Passport::actingAs($rrhh);
        $pendRrhh = $this->getJson('/api/hhee/pendientes');
        $pendRrhh->assertStatus(200);
        $idsRrhh = collect($pendRrhh->json('solicitudes'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$solicitudB->id, $solicitudC->id], $idsRrhh);

        // ?solo_total=1: no viajan las filas, solo el conteo (para polling).
        $totalRrhh = $this->getJson('/api/hhee/pendientes?solo_total=1');
        $totalRrhh->assertStatus(200);
        $totalRrhh->assertJsonPath('total', 2);
        $totalRrhh->assertJsonMissingPath('solicitudes');

        // El GL (solicitante, sin ningún rol de aprobación) no ve nada.
        Passport::actingAs($gl);
        $pendGl = $this->getJson('/api/hhee/pendientes');
        $pendGl->assertJsonPath('total', 0);
    }

    /**
     * Regresión dedicada de la exclusión por autoaprobación en /pendientes:
     * acá sí se da naturalmente sobre una solicitud que queda pendiente_final
     * (a diferencia de nivel 1, que con la firma implícita ya no llega a
     * "pendiente_nivel1 propia" para un aprobador de ese nivel). RRHH crea y
     * envía SU PROPIA solicitud; otro jefe aprueba nivel 1 (RRHH no tiene rol
     * nivel1, así que no hay firma implícita acá); la solicitud queda
     * pendiente_final, pero RRHH no debe verla en su propio /pendientes.
     */
    public function test_pendientes_excluye_la_propia_solicitud_pendiente_final_de_un_aprobador_nivel_final(): void
    {
        // El departamento de la solicitud SIEMPRE es el del solicitante (ver
        // App\Support\HheeFlujo): rrhh y el jefe que aprueba nivel 1 tienen
        // que pertenecer/estar escopeados al MISMO departamento para que el
        // jefe pueda firmar la solicitud que crea rrhh.
        $deptoRrhh = $this->departamento('RRHH');
        $jefe = $this->usuario($deptoRrhh);
        $rrhh = $this->usuario($deptoRrhh);
        $this->asignarRolHhee($jefe, 'jefe', $deptoRrhh->id);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $solicitud = $this->crearYEnviar($rrhh, $deptoRrhh);
        Passport::actingAs($jefe);
        $this->postJson("/api/hhee/solicitudes/{$solicitud->id}/aprobar")->assertStatus(200);
        $this->assertSame('pendiente_final', $solicitud->fresh()->estado);

        Passport::actingAs($rrhh);
        $pendientes = $this->getJson('/api/hhee/pendientes');
        $pendientes->assertStatus(200);
        $pendientes->assertJsonPath('total', 0);
    }

    // =========================================================================
    // Helper de flujo: crea un borrador con 1 detalle válido y lo envía.
    // =========================================================================

    private function crearYEnviar(User $solicitante, Departamento $departamento): SolicitudHhee
    {
        Passport::actingAs($solicitante);

        $id = $this->postJson('/api/hhee/solicitudes', $this->payload($departamento))->json('id');
        $this->postJson("/api/hhee/solicitudes/{$id}/enviar")->assertStatus(200);

        return SolicitudHhee::findOrFail($id);
    }
}
