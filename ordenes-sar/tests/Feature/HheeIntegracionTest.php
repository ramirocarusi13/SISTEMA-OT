<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\HheeRolAprobacion;
use App\Models\Notificacion;
use App\Models\SolicitudHhee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Endpoints de integración servidor-a-servidor de HHEE para APP-RRHH (ver
 * App\Http\Controllers\HheeIntegracionController,
 * App\Http\Middleware\VerificarIntegracionHhee, routes/api.php ->
 * prefix('hhee/integracion')). A diferencia del resto de la suite de HHEE,
 * estos endpoints NO usan Passport::actingAs() para autenticarse: se
 * autentican con el header X-Integracion-Key contra
 * config('hhee.integracion_key'), fijada acá con un valor de prueba
 * (setUp()). Passport::actingAs() SÍ se usa, puntualmente, para armar el
 * estado previo de la solicitud (crear/enviar/aprobar nivel 1) a través de
 * los endpoints normales del módulo.
 *
 * Entorno de DB: sqlite :memory: (RefreshDatabase), igual que
 * tests/Feature/HheeCircuitoTest.php.
 */
class HheeIntegracionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_VALIDA = 'test-integracion-key-1234567890abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config(['hhee.integracion_key' => self::KEY_VALIDA]);
    }

    // =========================================================================
    // Helpers de armado (mismo patrón que HheeCircuitoTest; se duplican acá a
    // propósito porque son privados a esa clase y este archivo no depende de
    // ella).
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

    private function asignarRolHhee(User $user, string $rol, ?int $departamentoId = null): HheeRolAprobacion
    {
        return HheeRolAprobacion::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'departamento_id' => $departamentoId,
            'activo' => true,
        ]);
    }

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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'fecha_hhee' => now()->toDateString(),
            'sector' => 'Corte',
            'turno' => 'Turno Mañana',
            'observaciones' => 'Solicitud de prueba',
            'detalles' => [$this->detalle()],
        ], $overrides);
    }

    /**
     * Crea, envía y aprueba nivel 1 (jefe legítimo del depto) vía los
     * endpoints normales (Passport::actingAs()): deja la solicitud en
     * pendiente_final, lista para que la integración apruebe/rechace la
     * firma final.
     */
    private function solicitudPendienteFinal(Departamento $depto): SolicitudHhee
    {
        $gl = $this->usuario($depto);
        $jefe = $this->usuario($depto);
        $this->asignarRolHhee($jefe, 'jefe', $depto->id);

        Passport::actingAs($gl);
        $id = $this->postJson('/api/hhee/solicitudes', $this->payload())->json('id');
        $this->postJson("/api/hhee/solicitudes/{$id}/enviar")->assertStatus(200);

        Passport::actingAs($jefe);
        $this->postJson("/api/hhee/solicitudes/{$id}/aprobar")->assertStatus(200);

        return SolicitudHhee::findOrFail($id);
    }

    // =========================================================================
    // Autenticación (header X-Integracion-Key)
    // =========================================================================

    public function test_sin_key_da_401(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $response = $this->postJson("/api/hhee/integracion/solicitudes/{$solicitud->id}/aprobar", [
            'email_firmante' => $rrhh->email,
        ]);

        $response->assertStatus(401);
        $this->assertSame('pendiente_final', $solicitud->fresh()->estado);
    }

    public function test_key_mala_da_401(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $response = $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/aprobar",
            ['email_firmante' => $rrhh->email],
            ['X-Integracion-Key' => 'clave-incorrecta']
        );

        $response->assertStatus(401);
        $this->assertSame('pendiente_final', $solicitud->fresh()->estado);
    }

    public function test_key_no_configurada_en_el_server_da_503(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);

        // Simula un server sin la variable de entorno cargada.
        config(['hhee.integracion_key' => null]);

        $response = $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/aprobar",
            ['email_firmante' => 'quien-sea@example.com'],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        );

        $response->assertStatus(503);
    }

    // =========================================================================
    // Aprobar / rechazar la firma final
    // =========================================================================

    public function test_aprobar_con_key_valida_y_rol_rrhh_aprueba_la_solicitud_y_notifica(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'), ['name' => 'Rita RRHH']);
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $response = $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/aprobar",
            ['email_firmante' => $rrhh->email, 'comentario' => 'OK desde RRHH'],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('estado', 'aprobada');
        $response->assertJsonPath('id', $solicitud->id);

        $solicitud->refresh();
        $this->assertSame('aprobada', $solicitud->estado);
        $this->assertNotNull($solicitud->fecha_aprobacion_final);

        $ap2 = $solicitud->aprobacionNivel(2)->first();
        $this->assertSame('aprobada', $ap2->estado);
        $this->assertSame($rrhh->id, $ap2->aprobador_id);
        $this->assertSame('rrhh', $ap2->rol_aprobador);
        $this->assertFalse((bool) $ap2->es_contingencia);
        $this->assertStringContainsString('OK desde RRHH', $ap2->comentario);
        $this->assertStringContainsString('(vía APP-RRHH)', $ap2->comentario);

        // Notificación al solicitante avisando que ya puede cargar horas reales
        // (misma matriz de notificaciones que la firma final "normal", ver
        // App\Support\HheeNotificador::notificarAprobadaFinal()).
        $this->assertTrue(
            Notificacion::where('usuario_creador_id', $solicitud->solicitante_id)
                ->where('tipo', 'hhee')
                ->where('solicitud_hhee_id', $solicitud->id)
                ->where('mensaje', 'like', '%Ya podés cargar las horas reales%')
                ->exists()
        );
    }

    public function test_aprobar_sin_comentario_deja_solo_el_sufijo_de_integracion(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $response = $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/aprobar",
            ['email_firmante' => $rrhh->email],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        );

        $response->assertStatus(200);

        $ap2 = $solicitud->fresh()->aprobacionNivel(2)->first();
        $this->assertSame('(vía APP-RRHH)', $ap2->comentario);
    }

    public function test_rechazar_con_key_valida_y_rol_rrhh_rechaza_la_solicitud(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $response = $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/rechazar",
            ['email_firmante' => $rrhh->email, 'motivo' => 'No corresponde el pago'],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('estado', 'rechazada');

        $solicitud->refresh();
        $this->assertSame('rechazada', $solicitud->estado);
        $this->assertSame($rrhh->id, $solicitud->rechazado_por_id);
        $this->assertStringContainsString('No corresponde el pago', $solicitud->motivo_rechazo);
        $this->assertStringContainsString('(vía APP-RRHH)', $solicitud->motivo_rechazo);

        $ap2 = $solicitud->aprobacionNivel(2)->first();
        $this->assertSame('rechazada', $ap2->estado);
        $this->assertSame($rrhh->id, $ap2->aprobador_id);
    }

    public function test_rechazar_sin_motivo_da_422(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $response = $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/rechazar",
            ['email_firmante' => $rrhh->email],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['motivo']);
        $this->assertSame('pendiente_final', $solicitud->fresh()->estado);
    }

    // =========================================================================
    // Autorización / datos del firmante
    // =========================================================================

    public function test_email_sin_rol_de_nivel_final_da_403(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);
        // team_member sin ningún rol HHEE.
        $intruso = $this->usuario($this->departamento('Otro'));

        $response = $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/aprobar",
            ['email_firmante' => $intruso->email],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        );

        $response->assertStatus(403);
        $this->assertSame('pendiente_final', $solicitud->fresh()->estado);
    }

    public function test_email_inexistente_da_404(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);

        $response = $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/aprobar",
            ['email_firmante' => 'no-existe@example.com'],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        );

        $response->assertStatus(404);
        $response->assertJsonPath('error', 'El firmante no existe como usuario del Sistema OT');
        $this->assertSame('pendiente_final', $solicitud->fresh()->estado);
    }

    public function test_solicitud_en_estado_no_firmable_da_422(): void
    {
        $depto = $this->departamento();
        $solicitud = $this->solicitudPendienteFinal($depto);
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        // Aprueba la firma final (queda 'aprobada', ya no hay nada pendiente).
        $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/aprobar",
            ['email_firmante' => $rrhh->email],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        )->assertStatus(200);

        // Un segundo intento de aprobar sobre una solicitud ya 'aprobada'
        // (sin nada pendiente) da 422 (estado), no 403 (rol).
        $response = $this->postJson(
            "/api/hhee/integracion/solicitudes/{$solicitud->id}/aprobar",
            ['email_firmante' => $rrhh->email],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['estado']);
    }

    public function test_solicitud_inexistente_da_404(): void
    {
        $rrhh = $this->usuario($this->departamento('RRHH'));
        $this->asignarRolHhee($rrhh, 'rrhh', null);

        $response = $this->postJson(
            '/api/hhee/integracion/solicitudes/999999/aprobar',
            ['email_firmante' => $rrhh->email],
            ['X-Integracion-Key' => self::KEY_VALIDA]
        );

        $response->assertStatus(404);
    }
}
