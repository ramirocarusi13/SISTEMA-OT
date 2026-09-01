<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Descripcion;
use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Support\Departamentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Regresión de hardening: DescripcionController::getDescripcionesByOrdenId()
 * no validaba ningún alcance (cualquier usuario autenticado podía leer las
 * descripciones de cualquier OT por id). Ahora usa
 * App\Support\AlcanceOrdenes::puedeVer(), el mismo criterio que
 * OrdenTrabajoController::show()/getFotoFinalizada() y
 * MensajeController::index().
 *
 * Entorno de DB: sqlite :memory: (RefreshDatabase), igual que el resto de la
 * suite de Feature de este proyecto.
 */
class DescripcionesAlcanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Default histórico de config('ot.departamentos.mantenimiento') es 2
        // (env('OT_DEPTO_MANTENIMIENTO_ID', 2)): con ids autoincrementales de
        // sqlite, el segundo departamento que crea un test cualquiera podría
        // "ser" Mantenimiento por accidente. Se fuerza a null acá (nadie es
        // Mantenimiento por default) y el único test que sí necesita ese rol
        // lo pisa explícitamente.
        config(['ot.departamentos.mantenimiento' => null]);

        // App\Support\Departamentos memoiza mantenimientoId()/seguridadId()
        // de forma estática (una sola query por PROCESO php, no por test).
        Departamentos::resetForTests();
    }

    private function departamento(string $nombre): Departamento
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

    private function ordenDe(User $creador, array $overrides = []): OrdenTrabajo
    {
        return OrdenTrabajo::create(array_merge([
            'usuario_id' => $creador->id,
            'titulo' => 'OT de prueba',
            'estado' => 'creada',
        ], $overrides));
    }

    private function descripcionDe(OrdenTrabajo $orden): Descripcion
    {
        return Descripcion::create([
            'titulo' => 'Detalle',
            'descripcion' => 'Descripción de prueba',
            'orden_id' => $orden->id,
        ]);
    }

    public function test_usuario_ve_las_descripciones_de_una_ot_de_su_propio_departamento(): void
    {
        $depto = $this->departamento('Producción');
        $creador = $this->usuario($depto, ['name' => 'Creador']);
        $lector = $this->usuario($depto, ['name' => 'Compañero']);
        $orden = $this->ordenDe($creador);
        $this->descripcionDe($orden);

        Passport::actingAs($lector);
        $response = $this->getJson("/api/ordenes-trabajo/{$orden->id}/descripciones");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    }

    public function test_usuario_no_ve_las_descripciones_de_una_ot_de_otro_departamento(): void
    {
        $deptoA = $this->departamento('Producción');
        $deptoB = $this->departamento('Calidad');
        $creador = $this->usuario($deptoA);
        $intruso = $this->usuario($deptoB);
        $orden = $this->ordenDe($creador);
        $this->descripcionDe($orden);

        Passport::actingAs($intruso);
        $response = $this->getJson("/api/ordenes-trabajo/{$orden->id}/descripciones");

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'No tiene permisos para ver esta orden');
    }

    public function test_usuario_syh_ve_las_descripciones_de_una_ot_de_seguridad_ajena(): void
    {
        $syh = $this->departamento('SyH');
        $otroDepto = $this->departamento('Producción');
        $usuarioSyh = $this->usuario($syh, ['name' => 'Usuario SyH']);
        $creadorAjeno = $this->usuario($otroDepto);

        $ordenSeguridad = $this->ordenDe($creadorAjeno, ['es_seguridad' => true]);
        $this->descripcionDe($ordenSeguridad);

        Passport::actingAs($usuarioSyh);
        $response = $this->getJson("/api/ordenes-trabajo/{$ordenSeguridad->id}/descripciones");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    }

    public function test_usuario_syh_no_ve_las_descripciones_de_una_ot_ajena_sin_marca_de_seguridad(): void
    {
        $syh = $this->departamento('SyH');
        $otroDepto = $this->departamento('Producción');
        $usuarioSyh = $this->usuario($syh);
        $creadorAjeno = $this->usuario($otroDepto);

        $ordenComun = $this->ordenDe($creadorAjeno, ['es_seguridad' => false]);
        $this->descripcionDe($ordenComun);

        Passport::actingAs($usuarioSyh);
        $response = $this->getJson("/api/ordenes-trabajo/{$ordenComun->id}/descripciones");

        $response->assertStatus(403);
    }

    public function test_ot_inexistente_da_404(): void
    {
        $depto = $this->departamento('Producción');
        $user = $this->usuario($depto);

        Passport::actingAs($user);
        $response = $this->getJson('/api/ordenes-trabajo/999999/descripciones');

        $response->assertStatus(404);
        $response->assertJsonPath('error', 'Orden de trabajo no encontrada');
    }

    public function test_usuario_de_mantenimiento_ve_las_descripciones_de_cualquier_departamento_regresion(): void
    {
        $mantenimiento = $this->departamento('Mantenimiento');
        config(['ot.departamentos.mantenimiento' => $mantenimiento->id]);
        Departamentos::resetForTests();

        $otroDepto = $this->departamento('Producción');
        $glMtto = $this->usuario($mantenimiento, ['rol' => 'group_leader']);
        $creadorAjeno = $this->usuario($otroDepto);

        $orden = $this->ordenDe($creadorAjeno);
        $this->descripcionDe($orden);

        Passport::actingAs($glMtto);
        $response = $this->getJson("/api/ordenes-trabajo/{$orden->id}/descripciones");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    }
}
