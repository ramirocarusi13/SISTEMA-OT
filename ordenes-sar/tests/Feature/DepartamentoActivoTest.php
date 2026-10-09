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
 * Switch de departamento (App\Http\Middleware\DepartamentoActivo): Agustín
 * Otero es gerente de Mantenimiento y además de Ingeniería. Con el header
 * X-Departamento-Activo = Ingeniería ve y aprueba las OTs de Ingeniería; sin
 * header sigue exactamente igual que siempre (gerente de Mantenimiento). Un
 * usuario sin el departamento adicional no puede usar el header.
 */
class DepartamentoActivoTest extends TestCase
{
    use RefreshDatabase;

    private Departamento $mantenimiento;
    private Departamento $ingenieria;
    private Departamento $produccion;
    private User $agustin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mantenimiento = Departamento::create(['nombre' => 'Mantenimiento']);
        $this->ingenieria = Departamento::create(['nombre' => 'Ingenieria']);
        $this->produccion = Departamento::create(['nombre' => 'Produccion']);

        config(['ot.departamentos.mantenimiento' => $this->mantenimiento->id]);
        Departamentos::resetForTests();

        $this->agustin = $this->usuario($this->mantenimiento, Roles::GERENTE, ['name' => 'Agustin Otero']);
        $this->agustin->departamentosAdicionales()->attach($this->ingenieria->id);
    }

    private function usuario(Departamento $departamento, string $rol, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'departamento_id' => $departamento->id,
            'rol' => $rol,
            'turno' => 'Turno Mañana',
        ], $overrides));
    }

    private function ordenDe(User $creador): OrdenTrabajo
    {
        return OrdenTrabajo::create([
            'usuario_id' => $creador->id,
            'titulo' => 'OT de prueba',
            'estado' => 'creada',
        ]);
    }

    private function idsVisibles(array $headers = []): array
    {
        return collect($this->getJson('/api/ordenes-trabajo', $headers)->assertOk()->json())
            ->pluck('id')->sort()->values()->all();
    }

    public function test_sin_header_sigue_como_gerente_de_mantenimiento(): void
    {
        $otIng = $this->ordenDe($this->usuario($this->ingenieria, 'team_member'));
        $otPrd = $this->ordenDe($this->usuario($this->produccion, 'team_member'));

        Passport::actingAs($this->agustin);

        $this->assertSame([$otIng->id, $otPrd->id], $this->idsVisibles());
        // Como gerente de Mantenimiento no aprueba OTs de Ingeniería (regla de siempre).
        $this->putJson("/api/ordenes-trabajo/{$otIng->id}/aprobar")->assertStatus(403);
    }

    public function test_con_ingenieria_activo_ve_y_aprueba_las_ots_de_ingenieria(): void
    {
        $otIng = $this->ordenDe($this->usuario($this->ingenieria, 'team_member'));
        $this->ordenDe($this->usuario($this->produccion, 'team_member'));

        Passport::actingAs($this->agustin);
        $headers = ['X-Departamento-Activo' => (string) $this->ingenieria->id];

        $this->assertSame([$otIng->id], $this->idsVisibles($headers));

        $this->putJson("/api/ordenes-trabajo/{$otIng->id}/aprobar", [], $headers)->assertOk();
        $this->assertSame('aprobada', $otIng->fresh()->estado);

        // Nunca se persiste el departamento del switch.
        $this->assertSame($this->mantenimiento->id, (int) $this->agustin->fresh()->departamento_id);
    }

    public function test_usuario_sin_departamento_adicional_no_puede_usar_el_header(): void
    {
        $otIng = $this->ordenDe($this->usuario($this->ingenieria, 'team_member'));
        $gerenteProduccion = $this->usuario($this->produccion, Roles::GERENTE);

        Passport::actingAs($gerenteProduccion);
        $headers = ['X-Departamento-Activo' => (string) $this->ingenieria->id];

        $this->assertSame([], $this->idsVisibles($headers));
        $this->putJson("/api/ordenes-trabajo/{$otIng->id}/aprobar", [], $headers)->assertStatus(403);
    }

    public function test_get_user_informa_los_departamentos_disponibles(): void
    {
        Passport::actingAs($this->agustin);

        $this->getJson('/api/user', ['X-Departamento-Activo' => (string) $this->ingenieria->id])
            ->assertOk()
            ->assertJsonPath('departamento_base_id', $this->mantenimiento->id)
            ->assertJsonPath('departamentos_disponibles.0.id', $this->mantenimiento->id)
            ->assertJsonPath('departamentos_disponibles.1.id', $this->ingenieria->id);
    }
}
