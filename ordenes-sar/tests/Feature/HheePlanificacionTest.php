<?php

namespace Tests\Feature;

use App\Jobs\SendHheeMailJob;
use App\Models\Departamento;
use App\Models\HheeRolAprobacion;
use App\Models\Notificacion;
use App\Models\SolicitudHhee;
use App\Models\User;
use App\Support\HheeEstados;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Pedido de RRHH (oct-2026): planificar HHEE de antemano con "desde/hasta".
 * Se genera una solicitud por día (mismo planificacion_id), las acciones se
 * pueden aplicar a todos los días juntos y los avisos salen una sola vez.
 * Ver App\Support\HheeFlujo::planificar() / accionEnPlanificacion().
 */
class HheePlanificacionTest extends TestCase
{
    use RefreshDatabase;

    private Departamento $depto;
    private User $gl;
    private User $jefe;
    private User $gerencia;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->depto = Departamento::create(['nombre' => 'Producción']);
        $this->gl = $this->usuario('group_leader');
        $this->jefe = $this->usuario('team_member');
        $this->gerencia = $this->usuario('team_member');

        HheeRolAprobacion::create(['user_id' => $this->jefe->id, 'rol' => 'jefe', 'departamento_id' => $this->depto->id, 'activo' => true]);
        HheeRolAprobacion::create(['user_id' => $this->gerencia->id, 'rol' => 'gerencia_general', 'departamento_id' => null, 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create([
            'departamento_id' => $this->depto->id,
            'rol' => $rol,
            'turno' => 'Turno Mañana',
        ]);
    }

    /** 2026-11-02 es lunes: lunes a viernes de dos semanas = 10 días. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'fecha_desde' => '2026-11-02',
            'fecha_hasta' => '2026-11-15',
            'dias_semana' => [1, 2, 3, 4, 5],
            'sector' => 'Costura',
            'turno' => 'Tarde',
            'detalles' => [
                ['legajo' => '101', 'nombre' => 'Pérez, Juan', 'motivo' => 'Stock / volumen', 'necesita_transporte' => false, 'hora_desde' => '14:00', 'hora_hasta' => '18:00'],
                ['legajo' => '102', 'nombre' => 'Gómez, Ana', 'motivo' => 'Stock / volumen', 'necesita_transporte' => false, 'hora_desde' => '14:00', 'hora_hasta' => '18:00'],
            ],
        ], $overrides);
    }

    private function planificar(array $overrides = []): string
    {
        Passport::actingAs($this->gl);

        return $this->postJson('/api/hhee/planificaciones', $this->payload($overrides))
            ->assertStatus(201)
            ->json('planificacion_id');
    }

    // =========================================================================
    // Alta de la planificación
    // =========================================================================

    public function test_genera_una_solicitud_por_dia_habil_del_rango(): void
    {
        Passport::actingAs($this->gl);

        $respuesta = $this->postJson('/api/hhee/planificaciones', $this->payload());

        $respuesta->assertStatus(201)->assertJsonPath('total', 10);

        $solicitudes = SolicitudHhee::orderBy('fecha_hhee')->get();
        $this->assertCount(10, $solicitudes);
        $this->assertCount(1, $solicitudes->pluck('planificacion_id')->unique());
        $this->assertNotNull($solicitudes->first()->planificacion_id);
        $this->assertSame('2026-11-02', $solicitudes->first()->fecha_hhee->toDateString());
        $this->assertSame('2026-11-13', $solicitudes->last()->fecha_hhee->toDateString());

        foreach ($solicitudes as $s) {
            $this->assertFalse($s->fecha_hhee->isWeekend());
            $this->assertSame(HheeEstados::BORRADOR, $s->estado);
            $this->assertSame(2, $s->total_empleados);
            $this->assertEquals(8.0, (float) $s->total_horas_teoricas);
            $this->assertSame('Costura', $s->sector);
        }
    }

    public function test_crear_y_enviar_manda_un_solo_aviso_al_aprobador(): void
    {
        $this->planificar(['enviar' => true]);

        $this->assertSame(10, SolicitudHhee::where('estado', HheeEstados::PENDIENTE_NIVEL1)->count());

        $avisosJefe = Notificacion::where('usuario_creador_id', $this->jefe->id)->get();
        $this->assertCount(1, $avisosJefe);
        $this->assertStringContainsString('Planificación de 10 días, del 02/11/2026 al 13/11/2026', $avisosJefe->first()->mensaje);

        Queue::assertPushed(SendHheeMailJob::class, 1);
    }

    public function test_sin_dias_que_coincidan_da_422(): void
    {
        Passport::actingAs($this->gl);

        // Sábado y domingo en un rango lunes-viernes.
        $this->postJson('/api/hhee/planificaciones', $this->payload([
            'fecha_desde' => '2026-11-02',
            'fecha_hasta' => '2026-11-06',
            'dias_semana' => [6, 7],
        ]))->assertStatus(422)->assertJsonValidationErrors('dias_semana');

        $this->assertSame(0, SolicitudHhee::count());
    }

    public function test_hasta_antes_que_desde_da_422(): void
    {
        Passport::actingAs($this->gl);

        $this->postJson('/api/hhee/planificaciones', $this->payload([
            'fecha_desde' => '2026-11-10',
            'fecha_hasta' => '2026-11-02',
        ]))->assertStatus(422)->assertJsonValidationErrors('fecha_hasta');
    }

    public function test_supera_el_maximo_de_dias_da_422_y_no_crea_nada(): void
    {
        config(['hhee.max_dias_planificacion' => 5]);
        Passport::actingAs($this->gl);

        $this->postJson('/api/hhee/planificaciones', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('fecha_hasta');

        $this->assertSame(0, SolicitudHhee::count());
    }

    public function test_un_dia_invalido_no_crea_ninguno(): void
    {
        Passport::actingAs($this->gl);

        $this->postJson('/api/hhee/planificaciones', $this->payload([
            'detalles' => [
                ['nombre' => 'Pérez, Juan', 'motivo' => 'Stock / volumen', 'hora_desde' => '14:00', 'hora_hasta' => '14:00'],
            ],
        ]))->assertStatus(422);

        $this->assertSame(0, SolicitudHhee::count());
    }

    public function test_team_member_no_puede_planificar(): void
    {
        Passport::actingAs($this->usuario('team_member'));

        $this->postJson('/api/hhee/planificaciones', $this->payload())->assertStatus(403);
        $this->assertSame(0, SolicitudHhee::count());
    }

    public function test_solicitud_suelta_no_tiene_planificacion(): void
    {
        Passport::actingAs($this->gl);

        $payload = $this->payload();
        unset($payload['fecha_desde'], $payload['fecha_hasta'], $payload['dias_semana']);
        // Aunque el cliente lo mande, store() no acepta planificacion_id.
        $payload['fecha_hhee'] = '2026-11-02';
        $payload['planificacion_id'] = 'inventado';

        $id = $this->postJson('/api/hhee/solicitudes', $payload)->assertStatus(201)->json('id');

        $this->assertNull(SolicitudHhee::find($id)->planificacion_id);
        $this->getJson("/api/hhee/solicitudes/{$id}")->assertJsonPath('planificacion', null);
    }

    // =========================================================================
    // Acciones sobre todos los días
    // =========================================================================

    public function test_enviar_todos_los_borradores_de_la_planificacion(): void
    {
        $plan = $this->planificar();

        $this->postJson("/api/hhee/planificaciones/{$plan}/enviar")
            ->assertStatus(200)
            ->assertJson(['procesadas' => 10, 'omitidas' => 0]);

        $this->assertSame(10, SolicitudHhee::where('estado', HheeEstados::PENDIENTE_NIVEL1)->count());
        $this->assertSame(1, Notificacion::where('usuario_creador_id', $this->jefe->id)->count());
    }

    public function test_circuito_completo_firmando_todos_los_dias_juntos(): void
    {
        $plan = $this->planificar(['enviar' => true]);

        Passport::actingAs($this->jefe);
        $this->postJson("/api/hhee/planificaciones/{$plan}/aprobar", ['comentario' => 'OK toda la quincena'])
            ->assertStatus(200)
            ->assertJson(['procesadas' => 10]);
        $this->assertSame(10, SolicitudHhee::where('estado', HheeEstados::PENDIENTE_FINAL)->count());
        $this->assertSame(1, Notificacion::where('usuario_creador_id', $this->gerencia->id)->count());

        Passport::actingAs($this->gerencia);
        $this->postJson("/api/hhee/planificaciones/{$plan}/aprobar")
            ->assertStatus(200)
            ->assertJson(['procesadas' => 10]);
        $this->assertSame(10, SolicitudHhee::where('estado', HheeEstados::APROBADA)->count());

        // Al solicitante le llega un solo "aprobada" (más el de nivel 1).
        $this->assertSame(2, Notificacion::where('usuario_creador_id', $this->gl->id)->where('estado_nuevo', '!=', HheeEstados::PENDIENTE_NIVEL1)->count());
    }

    public function test_solo_procesa_los_dias_que_el_usuario_puede_firmar(): void
    {
        $plan = $this->planificar(['enviar' => true]);

        // Un día lo anula el solicitante antes de la firma.
        $primero = SolicitudHhee::orderBy('fecha_hhee')->first();
        $this->postJson("/api/hhee/solicitudes/{$primero->id}/anular")->assertStatus(200);

        Passport::actingAs($this->jefe);
        $this->postJson("/api/hhee/planificaciones/{$plan}/aprobar")
            ->assertStatus(200)
            ->assertJson(['procesadas' => 9, 'omitidas' => 1]);
    }

    public function test_rechazar_todos_exige_motivo(): void
    {
        $plan = $this->planificar(['enviar' => true]);
        Passport::actingAs($this->jefe);

        $this->postJson("/api/hhee/planificaciones/{$plan}/rechazar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->postJson("/api/hhee/planificaciones/{$plan}/rechazar", ['motivo' => 'No hay presupuesto'])
            ->assertStatus(200)
            ->assertJson(['procesadas' => 10]);

        $this->assertSame(10, SolicitudHhee::where('estado', HheeEstados::RECHAZADA)->where('motivo_rechazo', 'No hay presupuesto')->count());
    }

    public function test_anular_todos(): void
    {
        $plan = $this->planificar(['enviar' => true]);

        $this->postJson("/api/hhee/planificaciones/{$plan}/anular")
            ->assertStatus(200)
            ->assertJson(['procesadas' => 10]);

        $this->assertSame(10, SolicitudHhee::where('estado', HheeEstados::ANULADA)->count());
    }

    public function test_quien_no_puede_firmar_recibe_422_y_no_cambia_nada(): void
    {
        $plan = $this->planificar(['enviar' => true]);

        // El propio solicitante no puede autoaprobar.
        $this->postJson("/api/hhee/planificaciones/{$plan}/aprobar")->assertStatus(422);

        $this->assertSame(10, SolicitudHhee::where('estado', HheeEstados::PENDIENTE_NIVEL1)->count());
    }

    public function test_planificacion_inexistente_da_404(): void
    {
        Passport::actingAs($this->gl);

        $this->postJson('/api/hhee/planificaciones/no-existe/enviar')->assertStatus(404);
    }

    public function test_el_detalle_muestra_la_planificacion_y_las_acciones_disponibles(): void
    {
        $plan = $this->planificar(['enviar' => true]);
        $una = SolicitudHhee::orderBy('fecha_hhee')->first();

        Passport::actingAs($this->jefe);
        $respuesta = $this->getJson("/api/hhee/solicitudes/{$una->id}");

        $respuesta->assertStatus(200)
            ->assertJsonPath('planificacion.id', $plan)
            ->assertJsonPath('planificacion.total', 10)
            ->assertJsonPath('planificacion.dias.0.fecha_hhee', '2026-11-02')
            ->assertJsonPath('planificacion.acciones.aprobar', 10)
            ->assertJsonPath('planificacion.acciones.anular', 0);

        $this->getJson("/api/hhee/solicitudes?planificacion_id={$plan}")->assertJsonPath('total', 10);
    }
}
