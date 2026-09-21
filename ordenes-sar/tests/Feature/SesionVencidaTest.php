<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Contrato del que depende el front para detectar una sesión vencida
 * (ot-front/src/Utils/sesion.js): sin sesión válida la API responde SIEMPRE
 * 401 en JSON. Antes respondía 500 (TypeError en Authenticate::redirectTo) si
 * el request pedía JSON, o 302 si no mandaba el header Accept, y el front no
 * tenía forma de enterarse de que el token había vencido.
 */
class SesionVencidaTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function token_invalido_pidiendo_json_responde_401_y_no_500()
    {
        $respuesta = $this->withHeaders([
            'Authorization' => 'Bearer token-invalido',
            'Accept' => 'application/json',
        ])->get('/api/user');

        $respuesta->assertStatus(401);
        $respuesta->assertJsonStructure(['message']);
    }

    /** @test */
    public function token_invalido_sin_header_accept_responde_401_y_no_redirige()
    {
        // Así llaman los fetch viejos del front (sin Accept: application/json).
        $respuesta = $this->withHeaders([
            'Authorization' => 'Bearer token-invalido',
        ])->get('/api/ordenes-trabajo');

        $respuesta->assertStatus(401);
        $this->assertFalse($respuesta->isRedirection());
    }

    /** @test */
    public function sin_token_responde_401_en_rutas_de_los_tres_modulos()
    {
        foreach (['/api/user', '/api/ordenes-trabajo', '/api/hhee/catalogos', '/api/open-issues/catalogos', '/api/notificaciones'] as $ruta) {
            $this->get($ruta)->assertStatus(401);
        }
    }

    /** @test */
    public function con_sesion_valida_la_verificacion_del_front_responde_200()
    {
        $depto = Departamento::create(['nombre' => 'IT']);
        $user = User::factory()->create([
            'departamento_id' => $depto->id,
            'rol' => 'team_member',
            'turno' => 'Turno Mañana',
        ]);

        Passport::actingAs($user);

        $this->getJson('/api/user')->assertStatus(200)->assertJsonPath('id', $user->id);
    }

    /** @test */
    public function el_login_con_credenciales_malas_no_cambia_su_respuesta()
    {
        // El 401/422 del propio login NO es una sesión vencida: el front lo
        // excluye del interceptor. Acá solo se fija que no sea un 500.
        $respuesta = $this->postJson('/api/login', ['email' => 'nadie@sar.com', 'password' => 'mala']);

        $this->assertContains($respuesta->status(), [401, 404, 422]);
    }
}
