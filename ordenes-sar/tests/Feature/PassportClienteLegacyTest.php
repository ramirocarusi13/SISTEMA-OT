<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client as PassportClient;
use Laravel\Passport\Passport;
use phpseclib4\Crypt\RSA;
use Tests\TestCase;

/**
 * Upgrade a Passport 13 con el esquema LEGACY de `oauth_clients` (el que hay
 * en producción, creado con Passport 12) e ids enteros.
 *
 * Fija el contrato de App\Models\Passport\Client: el usuario cuyo id coincide
 * con el id del Personal Access Client tiene que poder usar su token
 * (laravel/passport#1912). Corre 100% sobre sqlite :memory: y con llaves RSA
 * temporales: no toca storage/oauth-*.key ni ninguna base real.
 */
class PassportClienteLegacyTest extends TestCase
{
    private ?string $keyPathOriginal = null;

    private string $keyDir;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            $this->fail('Guard de seguridad: la conexión de test no es sqlite :memory:.');
        }

        // Llaves temporales (no se pisan las de storage/).
        $this->keyPathOriginal = Passport::$keyPath;
        $this->keyDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'passport-test-'.uniqid();
        mkdir($this->keyDir);
        $key = RSA::createKey(2048);
        file_put_contents($this->keyDir.'/oauth-private.key', (string) $key);
        file_put_contents($this->keyDir.'/oauth-public.key', (string) $key->getPublicKey());
        if (! windows_os()) {
            chmod($this->keyDir.'/oauth-private.key', 0600);
            chmod($this->keyDir.'/oauth-public.key', 0660);
        }
        Passport::loadKeysFrom($this->keyDir);

        // Esquema mínimo: users + las migraciones oauth_* reales del repo
        // (esquema legacy de Passport 12, igual al de producción).
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        foreach (glob(database_path('migrations/2016_06_01_00000*_create_oauth_*.php')) as $migracion) {
            (require $migracion)->up();
        }

        Route::middleware('auth:api')->get('/__test/passport-user', fn (Request $request) => [
            'id' => $request->user()->getKey(),
        ]);
    }

    protected function tearDown(): void
    {
        Passport::$keyPath = $this->keyPathOriginal;
        array_map('unlink', glob($this->keyDir.'/*'));
        @rmdir($this->keyDir);

        parent::tearDown();
    }

    private function crearPersonalAccessClientLegacy(): int
    {
        return DB::table('oauth_clients')->insertGetId([
            'user_id' => null,
            'name' => 'Laravel Personal Access Client',
            'secret' => 'secreto-en-texto-plano-como-en-passport-12',
            'provider' => null,
            'redirect' => 'http://localhost',
            'personal_access_client' => true,
            'password_client' => false,
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function crearUsuarioConId(int $id): User
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Usuario '.$id,
            'email' => "u{$id}@test.local",
            'password' => bcrypt('x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    public function test_usuario_con_mismo_id_que_el_personal_access_client_puede_usar_su_token(): void
    {
        $clientId = $this->crearPersonalAccessClientLegacy();
        $user = $this->crearUsuarioConId($clientId);

        $token = $user->createToken('Laravel Password Grant Client')->accessToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/__test/passport-user')
            ->assertOk()
            ->assertJsonPath('id', $clientId);

        $this->assertSame(1, DB::table('oauth_access_tokens')->where('client_id', $clientId)->count());
    }

    public function test_usuario_con_otro_id_puede_usar_su_token(): void
    {
        $clientId = $this->crearPersonalAccessClientLegacy();
        $user = $this->crearUsuarioConId($clientId + 41);

        $token = $user->createToken('Laravel Password Grant Client')->accessToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/__test/passport-user')
            ->assertOk()
            ->assertJsonPath('id', $clientId + 41);
    }

    public function test_sin_el_modelo_de_compatibilidad_passport_13_rechaza_al_usuario_con_mismo_id(): void
    {
        // Documenta por qué existe App\Models\Passport\Client.
        Passport::useClientModel(PassportClient::class);

        $clientId = $this->crearPersonalAccessClientLegacy();
        $user = $this->crearUsuarioConId($clientId);

        $token = $user->createToken('Laravel Password Grant Client')->accessToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/__test/passport-user')
            ->assertUnauthorized();
    }
}
