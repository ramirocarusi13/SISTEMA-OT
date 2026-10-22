<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\HheeRolAprobacion;
use App\Models\SolicitudHhee;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Pedido de RRHH (oct-2026): carga masiva de HHEE por sector con la nómina de
 * APP-RRHH, desplegable de motivos, sector Administración y solo group leader
 * para arriba puede cargar. Ver App\Support\HheeNomina / HheePermisos.
 */
class HheeCargaMasivaTest extends TestCase
{
    use RefreshDatabase;

    private function departamento(string $nombre = 'Producción'): Departamento
    {
        return Departamento::create(['nombre' => $nombre]);
    }

    private function usuario(string $rol, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'departamento_id' => $this->departamento('Depto ' . uniqid())->id,
            'rol' => $rol,
            'turno' => 'Turno Mañana',
        ], $overrides));
    }

    private function detalle(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Pérez, Juan',
            'motivo' => 'Reparaciones',
            'necesita_transporte' => false,
            'hora_desde' => '14:00',
            'hora_hasta' => '18:00',
        ], $overrides);
    }

    private function payload(array $detalles, array $overrides = []): array
    {
        return array_merge([
            'fecha_hhee' => now()->toDateString(),
            'sector' => 'Administración',
            'detalles' => $detalles,
        ], $overrides);
    }

    /**
     * Conexión 'rrhh' apuntando a un sqlite en memoria con las tablas
     * employees/departments de APP-RRHH (mismas columnas que se leen).
     */
    private function nominaFalsa(): void
    {
        config([
            'database.connections.rrhh' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'hhee.nomina.cache_minutos' => 0,
        ]);
        DB::purge('rrhh');

        Schema::connection('rrhh')->create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
        });
        Schema::connection('rrhh')->create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('legajo')->nullable();
            $t->string('nombre');
            $t->string('apellido');
            $t->string('puesto')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('estado_actual')->default('Activo');
        });

        $corte = DB::connection('rrhh')->table('departments')->insertGetId(['nombre' => 'Corte']);
        $admin = DB::connection('rrhh')->table('departments')->insertGetId(['nombre' => 'Administración']);

        DB::connection('rrhh')->table('employees')->insert([
            ['legajo' => '101', 'nombre' => 'Juan', 'apellido' => 'Pérez', 'puesto' => 'Operario', 'department_id' => $corte, 'estado_actual' => 'Activo'],
            ['legajo' => '102', 'nombre' => 'Ana', 'apellido' => 'Gómez', 'puesto' => 'Operaria', 'department_id' => $corte, 'estado_actual' => 'Activo'],
            ['legajo' => '201', 'nombre' => 'Luis', 'apellido' => 'Díaz', 'puesto' => 'Administrativo', 'department_id' => $admin, 'estado_actual' => 'Activo'],
            ['legajo' => '999', 'nombre' => 'Baja', 'apellido' => 'Vieja', 'puesto' => 'Operario', 'department_id' => $corte, 'estado_actual' => 'Inactivo'],
        ]);
    }

    // =========================================================================
    // Permiso de carga (GL para arriba)
    // =========================================================================

    public function test_team_member_no_puede_crear_solicitudes(): void
    {
        Passport::actingAs($this->usuario('team_member'));

        $this->postJson('/api/hhee/solicitudes', $this->payload([$this->detalle()]))->assertStatus(403);
        $this->getJson('/api/hhee/catalogos')->assertJsonPath('puede_cargar', false);
        $this->assertSame(0, SolicitudHhee::count());
    }

    public function test_group_leader_y_gerente_pueden_crear(): void
    {
        // 'admin' también está en config('hhee.roles_pueden_cargar'), pero el
        // enum users.rol de la migración original no lo incluye (sqlite lo
        // rechaza), así que acá se prueban los dos roles de la jerarquía.
        foreach (['group_leader', 'gerente'] as $rol) {
            Passport::actingAs($this->usuario($rol));
            $this->postJson('/api/hhee/solicitudes', $this->payload([$this->detalle()]))->assertStatus(201);
        }

        $this->assertSame(2, SolicitudHhee::count());
    }

    public function test_analista_no_puede_crear(): void
    {
        Passport::actingAs($this->usuario('analista'));

        $this->postJson('/api/hhee/solicitudes', $this->payload([$this->detalle()]))->assertStatus(403);
    }

    public function test_aprobador_hhee_puede_crear_aunque_su_rol_de_usuario_sea_team_member(): void
    {
        $jefe = $this->usuario('team_member');
        HheeRolAprobacion::create(['user_id' => $jefe->id, 'rol' => 'jefe', 'departamento_id' => $jefe->departamento_id, 'activo' => true]);

        Passport::actingAs($jefe);
        $this->postJson('/api/hhee/solicitudes', $this->payload([$this->detalle()]))->assertStatus(201);
    }

    public function test_habilitado_a_mano_puede_crear_y_si_se_desactiva_ya_no(): void
    {
        $administrativo = $this->usuario('team_member');
        DB::table('hhee_habilitados_carga')->insert([
            'user_id' => $administrativo->id,
            'activo' => true,
            'observacion' => 'Carga las HHEE de Administración',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs($administrativo);
        $this->getJson('/api/hhee/catalogos')->assertJsonPath('puede_cargar', true);
        $this->postJson('/api/hhee/solicitudes', $this->payload([$this->detalle()]))->assertStatus(201);

        DB::table('hhee_habilitados_carga')->where('user_id', $administrativo->id)->update(['activo' => false]);
        $this->postJson('/api/hhee/solicitudes', $this->payload([$this->detalle()]))->assertStatus(403);
    }

    // =========================================================================
    // Motivos y sector
    // =========================================================================

    public function test_motivo_fuera_del_desplegable_da_422(): void
    {
        Passport::actingAs($this->usuario('group_leader'));

        $this->postJson('/api/hhee/solicitudes', $this->payload([$this->detalle(['motivo' => 'Cualquier cosa'])]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('detalles.0.motivo');
    }

    public function test_acepta_todos_los_motivos_y_el_sector_administracion(): void
    {
        Passport::actingAs($this->usuario('group_leader'));

        $detalles = [];
        foreach (config('hhee.motivos') as $i => $motivo) {
            $detalles[] = $this->detalle(['nombre' => "Empleado {$i}", 'motivo' => $motivo]);
        }

        $respuesta = $this->postJson('/api/hhee/solicitudes', $this->payload($detalles));
        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('sector', 'Administración');
        $respuesta->assertJsonCount(count(config('hhee.motivos')), 'detalles');
    }

    // =========================================================================
    // Carga masiva con legajo de la nómina
    // =========================================================================

    public function test_carga_masiva_guarda_el_legajo_y_calcula_horas_de_cada_empleado(): void
    {
        Passport::actingAs($this->usuario('group_leader'));

        $respuesta = $this->postJson('/api/hhee/solicitudes', $this->payload([
            $this->detalle(['legajo' => '101', 'nombre' => 'Pérez, Juan']),
            $this->detalle(['legajo' => '102', 'nombre' => 'Gómez, Ana']),
            $this->detalle(['legajo' => '201', 'nombre' => 'Díaz, Luis']),
        ]));

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('total_empleados', 3);
        $respuesta->assertJsonPath('detalles.0.legajo', '101');
        $this->assertEquals(12.0, (float) $respuesta->json('total_horas_teoricas'));
    }

    public function test_mismo_legajo_dos_veces_es_duplicado(): void
    {
        Passport::actingAs($this->usuario('group_leader'));

        $this->postJson('/api/hhee/solicitudes', $this->payload([
            $this->detalle(['legajo' => '101', 'nombre' => 'Pérez, Juan']),
            $this->detalle(['legajo' => '101', 'nombre' => 'Perez Juan']),
        ]))->assertStatus(422)->assertJsonValidationErrors('detalles.1');
    }

    public function test_dos_homonimos_con_distinto_legajo_no_son_duplicado(): void
    {
        Passport::actingAs($this->usuario('group_leader'));

        $this->postJson('/api/hhee/solicitudes', $this->payload([
            $this->detalle(['legajo' => '301', 'nombre' => 'López, Carlos']),
            $this->detalle(['legajo' => '302', 'nombre' => 'López, Carlos']),
        ]))->assertStatus(201);
    }

    // =========================================================================
    // Nómina (APP-RRHH)
    // =========================================================================

    public function test_nomina_devuelve_solo_activos_con_sector_de_rrhh(): void
    {
        $this->nominaFalsa();
        Passport::actingAs($this->usuario('group_leader'));

        $respuesta = $this->getJson('/api/hhee/nomina');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('disponible', true);
        $respuesta->assertJsonCount(3, 'empleados');
        $respuesta->assertJsonFragment(['legajo' => '201', 'nombre' => 'Díaz, Luis', 'departamento' => 'Administración']);
        $respuesta->assertJsonMissing(['legajo' => '999']);
    }

    public function test_nomina_sin_conexion_configurada_no_rompe(): void
    {
        config(['database.connections.rrhh.host' => null, 'database.connections.rrhh.driver' => 'sqlsrv']);
        Passport::actingAs($this->usuario('group_leader'));

        $this->getJson('/api/hhee/nomina')
            ->assertStatus(200)
            ->assertJson(['disponible' => false, 'empleados' => []]);
    }

    public function test_nomina_solo_para_quien_puede_cargar(): void
    {
        $this->nominaFalsa();
        Passport::actingAs($this->usuario('team_member'));

        $this->getJson('/api/hhee/nomina')->assertStatus(403);
    }
}
