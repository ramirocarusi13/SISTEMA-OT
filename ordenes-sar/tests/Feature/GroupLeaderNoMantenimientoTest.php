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
 * Group leaders de departamentos que NO son Mantenimiento (ej. Marina Farias,
 * de Producción) conservan TODAS sus funciones normales de OT de su propio
 * departamento (crear, ver, comentar), exactamente igual que cualquier otro
 * usuario de ese departamento — no existe ninguna regla que los limite a
 * "solo Horas Extras". Lo único que no ganan son las funciones de group
 * leader de Mantenimiento (asignación/finalización de OTs de MTTO), y esas ya
 * están naturalmente atadas al departamento de Mantenimiento
 * (App\Support\Departamentos::esMantenimiento()), sin necesidad de ninguna
 * regla extra: un group_leader de Producción simplemente no pertenece a ese
 * departamento.
 *
 * Entorno de DB: sqlite :memory: (RefreshDatabase), igual que
 * tests/Feature/HheeCircuitoTest.php.
 */
class GroupLeaderNoMantenimientoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // App\Support\Departamentos memoiza mantenimientoId() de forma
        // estática (una sola query por PROCESO php, no por test): sin este
        // reset, un test anterior podría dejar un valor stale para los tests
        // de esta clase (que corren en el mismo proceso phpunit).
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

    private function ordenDe(User $creador, string $titulo = 'Reparar impresora'): OrdenTrabajo
    {
        return OrdenTrabajo::create([
            'usuario_id' => $creador->id,
            'titulo' => $titulo,
            'estado' => 'creada',
        ]);
    }

    public function test_group_leader_de_produccion_ve_las_ot_de_su_propio_departamento_como_cualquier_usuario(): void
    {
        $produccion = $this->departamento('Producción');
        $otroDepto = $this->departamento('Calidad');
        $marina = $this->usuario($produccion, Roles::GROUP_LEADER, ['name' => 'Marina Farias']);

        $ordenPropia = $this->ordenDe($marina, 'OT de Producción (Marina)');
        $ordenMismoDepto = $this->ordenDe($this->usuario($produccion, 'team_member'), 'Otra OT de Producción');
        $this->ordenDe($this->usuario($otroDepto, 'team_member'), 'OT de otro departamento');

        Passport::actingAs($marina);
        $index = $this->getJson('/api/ordenes-trabajo');

        $index->assertStatus(200);
        $ids = collect($index->json())->pluck('id')->all();

        // Ve las 2 OTs de SU departamento (la propia y la de un compañero),
        // no la de otro departamento: mismo comportamiento que cualquier
        // usuario que no sea Mantenimiento/admin/SyH.
        $this->assertEqualsCanonicalizing([$ordenPropia->id, $ordenMismoDepto->id], $ids);
    }

    public function test_group_leader_de_produccion_puede_crear_ver_y_comentar_una_ot(): void
    {
        $produccion = $this->departamento('Producción');
        $marina = $this->usuario($produccion, Roles::GROUP_LEADER, ['name' => 'Marina Farias']);

        Passport::actingAs($marina);

        $crear = $this->postJson('/api/ordenes-trabajo', [
            'titulo' => 'Pedido de Marina',
            'descripcion' => 'Descripción de prueba',
            'estado' => 'creada',
        ]);
        $crear->assertStatus(201);
        $id = $crear->json('id');

        $show = $this->getJson("/api/ordenes-trabajo/{$id}");
        $show->assertStatus(200);

        $mensaje = $this->postJson('/api/mensajes', [
            'orden_trabajo_id' => $id,
            'mensaje' => 'Un comentario de prueba',
        ]);
        $mensaje->assertStatus(201);
    }

    public function test_group_leader_de_otro_departamento_puede_crear_una_solicitud_hhee_normalmente(): void
    {
        $produccion = $this->departamento('Producción');
        $marina = $this->usuario($produccion, Roles::GROUP_LEADER, ['name' => 'Marina Farias']);

        Passport::actingAs($marina);
        $crear = $this->postJson('/api/hhee/solicitudes', [
            'fecha_hhee' => now()->toDateString(),
            'departamento_id' => $produccion->id,
            'turno' => 'Turno Mañana',
            'detalles' => [[
                'nombre' => 'Juan Pérez',
                'legajo' => '123',
                'motivo' => 'Refuerzo de turno',
                'necesita_transporte' => false,
                'hora_desde' => '08:00',
                'hora_hasta' => '16:00',
                'cruza_medianoche' => false,
                'hs_teoricas_50' => 8,
                'hs_teoricas_100' => 0,
                'hs_teoricas_50n' => 0,
                'hs_teoricas_100n' => 0,
            ]],
        ]);

        $crear->assertStatus(201);
        $crear->assertJsonPath('estado', 'borrador');
        $crear->assertJsonPath('solicitante_id', $marina->id);
    }

    public function test_group_leader_de_mantenimiento_sigue_viendo_todas_las_ot_regresion(): void
    {
        $mantenimiento = $this->departamento('Mantenimiento');
        config(['ot.departamentos.mantenimiento' => $mantenimiento->id]);
        Departamentos::resetForTests();

        $produccion = $this->departamento('Producción');
        $glMtto = $this->usuario($mantenimiento, Roles::GROUP_LEADER, ['name' => 'GL Mantenimiento']);

        $this->ordenDe($this->usuario($produccion, 'team_member'), 'OT de Producción');
        $this->ordenDe($this->usuario($mantenimiento, 'team_member'), 'OT de Mantenimiento');

        Passport::actingAs($glMtto);
        $index = $this->getJson('/api/ordenes-trabajo');

        $index->assertStatus(200);
        $this->assertCount(2, $index->json());
    }
}
