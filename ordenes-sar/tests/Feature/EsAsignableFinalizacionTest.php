<?php

namespace Tests\Feature;

use App\Http\Roles;
use App\Models\Departamento;
use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Support\Departamentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * users.es_asignable (ver migración
 * 2026_09_08_000001_add_es_asignable_to_users_table.php): marca INDIVIDUAL
 * (no por rol) de qué usuarios pueden recibir OTs asignadas y finalizarlas,
 * además de los group_leader de siempre. Caso real: Marcelo Ferreyra
 * (gerente) pasa al departamento Mantenimiento para asignar OTs como
 * Lezcano, pero también debe poder recibir OTs asignadas y finalizarlas él
 * mismo. Decisión explícita del negocio: NO abrir esto a todos los
 * gerentes, solo a usuarios marcados a mano.
 *
 * La regla nueva vive en OrdenTrabajoController::updateEstado(): "el
 * asignado de la orden puede finalizarla", sin importar su rol.
 * users.es_asignable NO se chequea en ese gate (alcanza con ser el
 * usuario_mantenimiento_id de esa OT puntual, igual que la regla ya
 * existente para group_leader): el flag gobierna a quién puede elegir el
 * front como asignable al momento de asignar, que es un tema aparte, fuera
 * de este alcance.
 *
 * Entorno de DB: sqlite :memory: (RefreshDatabase), igual que
 * tests/Feature/GroupLeaderNoMantenimientoTest.php.
 */
class EsAsignableFinalizacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // App\Support\Departamentos memoiza mantenimientoId() de forma
        // estática (una sola query por PROCESO php, no por test).
        Departamentos::resetForTests();
    }

    private function departamento(string $nombre): Departamento
    {
        return Departamento::create(['nombre' => $nombre]);
    }

    private function usuario(Departamento $departamento, string $rol, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'departamento_id' => $departamento->id,
            'rol' => $rol,
            'turno' => 'Turno Mañana',
        ], $overrides));
    }

    private function ordenDe(User $creador, array $overrides = []): OrdenTrabajo
    {
        return OrdenTrabajo::create(array_merge([
            'usuario_id' => $creador->id,
            'titulo' => 'OT de prueba',
            'estado' => 'creada',
        ], $overrides));
    }

    /**
     * Departamento "Mantenimiento" de prueba, deliberadamente con un id
     * DISTINTO de 2: OrdenTrabajoController::updateEstado() tiene una rama
     * legacy hardcodeada a "departamento_id === 2" (gerente de
     * mantenimiento, chequeo pre-existente e independiente de esta tarea)
     * que NO debe confundirse con la regla nueva bajo test ("el asignado
     * puede finalizar"). Crear primero un depto "filler" asegura que
     * Mantenimiento no caiga en el id 2 por casualidad del autoincremental
     * de sqlite, así el 200/403 de cada test se explica SOLO por la regla
     * nueva.
     */
    private function departamentoMantenimiento(): Departamento
    {
        // Dos fillers (no uno): el primero ocuparía justo el id 2, que es lo
        // que se quiere evitar.
        $this->departamento('Filler 1 (evita colisión con id 2)');
        $this->departamento('Filler 2 (evita colisión con id 2)');
        $mantenimiento = $this->departamento('Mantenimiento');

        config(['ot.departamentos.mantenimiento' => $mantenimiento->id]);
        Departamentos::resetForTests();

        $this->assertNotSame(2, $mantenimiento->id, 'Guard del propio test: no debe coincidir con el id 2.');

        return $mantenimiento;
    }

    public function test_gerente_de_mantenimiento_es_asignable_puede_recibir_asignacion_y_finalizar_su_propia_orden(): void
    {
        $mantenimiento = $this->departamentoMantenimiento();
        $otroDepto = $this->departamento('Producción');
        $creador = $this->usuario($otroDepto, 'team_member');
        $marcelo = $this->usuario($mantenimiento, Roles::GERENTE, [
            'name' => 'Marcelo Ferreyra',
            'es_asignable' => true,
        ]);

        $orden = $this->ordenDe($creador);

        Passport::actingAs($marcelo);

        $asignar = $this->putJson("/api/ordenes-trabajo/{$orden->id}/estado", [
            'estado' => 'asignada',
            'usuario_mantenimiento_id' => $marcelo->id,
        ]);
        $asignar->assertStatus(200);
        $this->assertSame($marcelo->id, $orden->fresh()->usuario_mantenimiento_id);

        $finalizar = $this->putJson("/api/ordenes-trabajo/{$orden->id}/estado", [
            'estado' => 'finalizada',
            'mensaje_finalizacion' => 'Listo, resuelto.',
        ]);
        $finalizar->assertStatus(200);
        $this->assertSame('finalizada', $orden->fresh()->estado);
        $this->assertSame($marcelo->id, $orden->fresh()->finalizado_por_id);
    }

    public function test_gerente_no_asignado_no_puede_finalizar_una_orden_ajena(): void
    {
        $mantenimiento = $this->departamentoMantenimiento();
        $otroDepto = $this->departamento('Producción');
        $creador = $this->usuario($otroDepto, 'team_member');
        $marcelo = $this->usuario($mantenimiento, Roles::GERENTE, [
            'name' => 'Marcelo Ferreyra',
            'es_asignable' => true,
        ]);
        $otroTecnico = $this->usuario($mantenimiento, Roles::GROUP_LEADER, ['name' => 'Otro Técnico']);

        $orden = $this->ordenDe($creador, [
            'usuario_mantenimiento_id' => $otroTecnico->id,
            'estado' => 'asignada',
        ]);

        Passport::actingAs($marcelo);
        $finalizar = $this->putJson("/api/ordenes-trabajo/{$orden->id}/estado", [
            'estado' => 'finalizada',
            'mensaje_finalizacion' => 'Intento no autorizado',
        ]);

        $finalizar->assertStatus(403);
        $this->assertSame('asignada', $orden->fresh()->estado);
    }

    public function test_group_leader_sigue_pudiendo_finalizar_solo_las_suyas_regresion(): void
    {
        $mantenimiento = $this->departamentoMantenimiento();
        $otroDepto = $this->departamento('Producción');
        $creador = $this->usuario($otroDepto, 'team_member');
        $gl = $this->usuario($mantenimiento, Roles::GROUP_LEADER, ['name' => 'GL Propio']);
        $otroGl = $this->usuario($mantenimiento, Roles::GROUP_LEADER, ['name' => 'GL Ajeno']);

        $ordenPropia = $this->ordenDe($creador, ['usuario_mantenimiento_id' => $gl->id, 'estado' => 'asignada']);
        $ordenAjena = $this->ordenDe($creador, ['usuario_mantenimiento_id' => $otroGl->id, 'estado' => 'asignada']);

        Passport::actingAs($gl);

        $finalizarPropia = $this->putJson("/api/ordenes-trabajo/{$ordenPropia->id}/estado", [
            'estado' => 'finalizada',
            'mensaje_finalizacion' => 'Listo',
        ]);
        $finalizarPropia->assertStatus(200);
        $this->assertSame('finalizada', $ordenPropia->fresh()->estado);

        $finalizarAjena = $this->putJson("/api/ordenes-trabajo/{$ordenAjena->id}/estado", [
            'estado' => 'finalizada',
            'mensaje_finalizacion' => 'No debería poder',
        ]);
        $finalizarAjena->assertStatus(403);
        $this->assertSame('asignada', $ordenAjena->fresh()->estado);
    }

    public function test_get_usuarios_mantenimiento_incluye_el_flag_es_asignable(): void
    {
        // getUsuariosMantenimiento() filtra por departamento_id = 2 A MANO
        // (no por config): se arma un depto "filler" primero para que
        // "Mantenimiento" quede exactamente en el id 2 acá (a diferencia del
        // resto de los tests de este archivo, que lo evitan a propósito).
        $this->departamento('Filler');
        $mantenimiento = $this->departamento('Mantenimiento');
        $this->assertSame(2, $mantenimiento->id);

        $marcelo = $this->usuario($mantenimiento, Roles::GERENTE, [
            'name' => 'Marcelo Ferreyra',
            'es_asignable' => true,
        ]);
        $otro = $this->usuario($mantenimiento, 'team_member', [
            'name' => 'Sin marcar',
            'es_asignable' => false,
        ]);

        Passport::actingAs($marcelo);
        $response = $this->getJson('/api/usuarios-mantenimiento');

        $response->assertStatus(200);
        $usuarios = collect($response->json());

        $entradaMarcelo = $usuarios->firstWhere('id', $marcelo->id);
        $this->assertNotNull($entradaMarcelo);
        $this->assertTrue((bool) $entradaMarcelo['es_asignable']);

        $entradaOtro = $usuarios->firstWhere('id', $otro->id);
        $this->assertNotNull($entradaOtro);
        $this->assertFalse((bool) $entradaOtro['es_asignable']);
    }
}
