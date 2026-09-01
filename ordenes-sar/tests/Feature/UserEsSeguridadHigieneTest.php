<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\User;
use App\Support\Departamentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Regresión: un usuario de Seguridad e Higiene (SyH) no veía el resumen por
 * departamentos en Reportes porque el front no tenía forma de saber que era
 * SyH al loguearse -- AuthController::login() serializa el modelo User
 * CRUDO (response()->json(['user' => $user, ...])), y el front guarda ESE
 * user en localStorage sin volver a pedir GET /api/user.
 * App\Models\User::getEsSeguridadHigieneAttribute() (accessor + $appends) es
 * lo que hace que 'es_seguridad_higiene' viaje en ESA respuesta (y en
 * cualquier otra serialización de User), no solo en
 * UserController::getAuthenticatedUser() (que antes lo calculaba a mano).
 *
 * Entorno de DB: sqlite :memory: (RefreshDatabase), igual que el resto de la
 * suite de Feature de este módulo.
 */
class UserEsSeguridadHigieneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // App\Support\Departamentos memoiza seguridadId() de forma estática
        // (una sola query por PROCESO php, no por test): sin este reset, un
        // test anterior podría dejar un valor stale para los tests de esta
        // clase (que corren en el mismo proceso phpunit).
        Departamentos::resetForTests();
    }

    private function usuario(Departamento $departamento, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'departamento_id' => $departamento->id,
            'rol' => 'team_member',
            'turno' => 'Turno Mañana',
        ], $overrides));
    }

    public function test_el_flag_es_true_para_un_usuario_del_departamento_syh(): void
    {
        $syh = Departamento::create(['nombre' => 'SyH']);
        $user = $this->usuario($syh);

        $this->assertTrue($user->es_seguridad_higiene);
        $this->assertTrue($user->toArray()['es_seguridad_higiene']);
    }

    public function test_el_flag_es_false_para_un_usuario_de_otro_departamento(): void
    {
        // El departamento SyH existe (para descartar que el false sea porque
        // Departamentos::seguridadId() no lo encontró y no porque el usuario
        // realmente pertenece a otro departamento).
        Departamento::create(['nombre' => 'SyH']);
        $otro = Departamento::create(['nombre' => 'Producción']);
        $user = $this->usuario($otro);

        $this->assertFalse($user->es_seguridad_higiene);
        $this->assertFalse($user->toArray()['es_seguridad_higiene']);
    }

    public function test_el_flag_viaja_en_get_user(): void
    {
        $syh = Departamento::create(['nombre' => 'SyH']);
        $user = $this->usuario($syh);

        Passport::actingAs($user);
        $response = $this->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJsonPath('es_seguridad_higiene', true);
    }

    public function test_el_flag_viaja_en_la_respuesta_cruda_del_login(): void
    {
        $syh = Departamento::create(['nombre' => 'SyH']);
        $user = $this->usuario($syh, ['email' => 'syh@example.com']);

        // AuthController::login() compara la contraseña en TEXTO PLANO
        // ($request->password !== $user->password); App\Models\User tiene el
        // cast 'hashed' en password, así que si se asigna vía Eloquent
        // (factory/mass assignment) queda hasheada. Se fuerza el valor plano
        // con una query builder cruda (bypassea el cast del modelo), igual
        // que están las contraseñas reales de esta app hoy.
        DB::table('users')->where('id', $user->id)->update(['password' => 'secreto123']);

        // login() emite un token REAL de Passport (a diferencia de
        // Passport::actingAs(), que no lo necesita): hace falta un "personal
        // access client" en la base para que $user->createToken() pueda
        // firmar el JWT.
        Artisan::call('passport:client', [
            '--personal' => true,
            '--name' => 'Test Personal Access Client',
            '--no-interaction' => true,
        ]);

        $login = $this->postJson('/api/login', [
            'email' => 'syh@example.com',
            'password' => 'secreto123',
        ]);

        $login->assertStatus(200);
        $login->assertJsonPath('user.es_seguridad_higiene', true);
    }
}
