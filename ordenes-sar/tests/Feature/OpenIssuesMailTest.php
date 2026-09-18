<?php

namespace Tests\Feature;

use App\Jobs\SendHheeMailJob;
use App\Mail\OpenIssueMail;
use App\Models\Departamento;
use App\Models\OpenIssue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Mail del módulo Open Issues (App\Support\OpenIssueNotificador::mail() +
 * App\Mail\OpenIssueMail), calcado del patrón de HHEE. No toca los tests
 * existentes de campana (tests/Feature/OpenIssuesCircuitoTest.php); acá solo
 * se verifica qué se ENCOLA vía App\Jobs\SendHheeMailJob (reusado, sin
 * Mail::send síncrono).
 */
class OpenIssuesMailTest extends TestCase
{
    use RefreshDatabase;

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

    private function payloadIssue(array $overrides = []): array
    {
        return array_merge([
            'titulo' => 'Funda 47A con hilo suelto en costura lateral',
            'descripcion' => 'Se detectó en control de calidad sobre unidades del turno noche.',
        ], $overrides);
    }

    private function crearIssueVia(User $actor, array $overrides = []): OpenIssue
    {
        Passport::actingAs($actor);

        $resp = $this->postJson('/api/open-issues', $this->payloadIssue($overrides));
        $resp->assertStatus(201);

        return OpenIssue::findOrFail($resp->json('id'));
    }

    /**
     * Jobs de mail de open issue encolados con evento $evento, filtrados
     * opcionalmente por email de destino. Usa Queue::pushed() (NO
     * Queue::assertPushed(): esa asume que hay al menos 1 match y falla la
     * suite entera cuando el propio test lo usa para comprobar que NO se
     * encoló nada, ej. jobsMailOpenIssue(..., $actor->email) esperando 0).
     */
    private function jobsMailOpenIssue(string $evento, ?string $email = null): \Illuminate\Support\Collection
    {
        return Queue::pushed(SendHheeMailJob::class, function (SendHheeMailJob $job) use ($evento, $email) {
            $mailable = $job->mailable();

            if (!$mailable instanceof OpenIssueMail || $mailable->evento !== $evento) {
                return false;
            }

            if ($email !== null && $job->destinatarioEmail() !== $email) {
                return false;
            }

            return true;
        });
    }

    // =========================================================================
    // (a) alta con involucrados -> mail 'involucrado' a cada uno, nunca al creador/actor
    // =========================================================================

    public function test_crear_con_involucrados_encola_mail_involucrado_a_cada_uno_sin_el_creador(): void
    {
        Queue::fake();

        $deptoDestino = $this->departamento('Calidad');
        $deptoCreador = $this->departamento('IT');
        $creador = $this->usuario($deptoCreador);
        $u1 = $this->usuario($deptoCreador, ['name' => 'Involucrado Uno']);
        $u2 = $this->usuario($deptoCreador, ['name' => 'Involucrado Dos']);

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$u1->id, $u2->id],
        ]);

        Queue::assertPushed(SendHheeMailJob::class, 2);

        $jobsU1 = $this->jobsMailOpenIssue('involucrado', $u1->email);
        $jobsU2 = $this->jobsMailOpenIssue('involucrado', $u2->email);

        $this->assertCount(1, $jobsU1);
        $this->assertCount(1, $jobsU2);

        $mailable = $jobsU1->first()->mailable();
        $this->assertSame($issue->id, $mailable->issue->id);
        $this->assertSame('involucrado', $mailable->evento);

        // Nunca un mail al creador/actor.
        $this->assertCount(0, $this->jobsMailOpenIssue('involucrado', $creador->email));
    }

    // =========================================================================
    // (b) comentar -> mail 'actualizacion' a creador + involucrados menos el actor
    // =========================================================================

    public function test_comentar_encola_mail_actualizacion_a_creador_e_involucrados_menos_el_actor(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $deptoCreador = $this->departamento('IT');
        $creador = $this->usuario($deptoCreador);
        $u1 = $this->usuario($deptoCreador, ['name' => 'Comentarista']);
        $u2 = $this->usuario($deptoCreador, ['name' => 'Testigo']);

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$u1->id, $u2->id],
        ]);

        Queue::fake();

        Passport::actingAs($u1);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", [
            'texto' => 'Comentario de prueba',
        ]);
        $resp->assertStatus(201);

        Queue::assertPushed(SendHheeMailJob::class, 2);

        $jobsCreador = $this->jobsMailOpenIssue('actualizacion', $creador->email);
        $jobsU2 = $this->jobsMailOpenIssue('actualizacion', $u2->email);

        $this->assertCount(1, $jobsCreador);
        $this->assertCount(1, $jobsU2);

        $mailable = $jobsCreador->first()->mailable();
        $this->assertSame('Comentario de prueba', $mailable->texto);

        // Nunca al actor (u1).
        $this->assertCount(0, $this->jobsMailOpenIssue('actualizacion', $u1->email));
    }

    public function test_cambio_de_estado_encola_mail_cambio_estado_con_label(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = $this->crearIssueVia($creador, ['departamento_destino_id' => $deptoDestino->id]);

        Queue::fake();

        Passport::actingAs($creador);
        // El creador es el único involucrado acá, así que el actor no debería
        // recibir mail; agrego otro involucrado antes de este posteo para
        // tener un destinatario real.
        $otro = $this->usuario($this->departamento('Otro'));
        $this->postJson("/api/open-issues/{$issue->id}/involucrados", ['user_ids' => [$otro->id]])->assertStatus(200);

        Queue::fake();

        $resp = $this->postJson("/api/open-issues/{$issue->id}/actualizaciones", [
            'nuevo_estado' => 'en_progreso',
        ]);
        $resp->assertStatus(201);

        $jobs = $this->jobsMailOpenIssue('cambio_estado', $otro->email);
        $this->assertCount(1, $jobs);
        $this->assertSame('En progreso', $jobs->first()->mailable()->estadoNuevo);
    }

    // =========================================================================
    // (c) cerrar -> mail 'cierre'
    // =========================================================================

    public function test_cerrar_encola_mail_cierre(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $involucrado = $this->usuario($this->departamento('Otro'));

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$involucrado->id],
        ]);

        Queue::fake();

        Passport::actingAs($creador);
        $resp = $this->postJson("/api/open-issues/{$issue->id}/cerrar", ['texto' => 'Resuelto']);
        $resp->assertStatus(200);

        $jobs = $this->jobsMailOpenIssue('cierre', $involucrado->email);
        $this->assertCount(1, $jobs);
        $this->assertSame('Resuelto', $jobs->first()->mailable()->texto);
    }

    // =========================================================================
    // (d) quitar involucrado -> NO se encola mail
    // =========================================================================

    public function test_quitar_involucrado_no_encola_mail(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $otro = $this->usuario($this->departamento('Otro'));

        $issue = $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$otro->id],
        ]);

        Queue::fake();

        Passport::actingAs($creador);
        $resp = $this->deleteJson("/api/open-issues/{$issue->id}/involucrados/{$otro->id}");
        $resp->assertStatus(200);

        Queue::assertNotPushed(SendHheeMailJob::class);
    }

    // =========================================================================
    // (e) mail apagado por config -> nada se encola
    // =========================================================================

    public function test_con_mail_deshabilitado_no_se_encola_nada(): void
    {
        config(['open_issues.mail.enabled' => false]);

        Queue::fake();

        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));
        $involucrado = $this->usuario($this->departamento('Otro'));

        $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$involucrado->id],
        ]);

        Queue::assertNotPushed(SendHheeMailJob::class);
    }

    // =========================================================================
    // (f) render del mailable: HTML + texto plano + asunto
    // =========================================================================

    public function test_render_del_mailable_contiene_titulo_url_y_asunto_correcto(): void
    {
        $deptoDestino = $this->departamento('Calidad');
        $creador = $this->usuario($this->departamento('IT'));

        $issue = OpenIssue::create([
            'titulo' => 'Título de prueba para el mail',
            'departamento_destino_id' => $deptoDestino->id,
            'creador_id' => $creador->id,
            'estado' => 'abierto',
            'prioridad' => 'media',
        ]);

        $mailable = new OpenIssueMail($issue, $creador, 'involucrado');
        $mailable->assertSeeInHtml($issue->titulo);
        $mailable->assertSeeInHtml("open-issues?issue={$issue->id}");
        $mailable->assertHasSubject("Open Issue #{$issue->id}: te involucraron en \"{$issue->titulo}\"");

        $built = $mailable->build();
        $this->assertNotEmpty($built->textView);

        $renderedTexto = $built->render();
        $this->assertNotEmpty($renderedTexto);
    }

    // =========================================================================
    // (g) usuario sin email no recibe job
    // =========================================================================

    public function test_usuario_sin_email_no_recibe_job(): void
    {
        Queue::fake();

        $deptoDestino = $this->departamento('Calidad');
        $deptoCreador = $this->departamento('IT');
        $creador = $this->usuario($deptoCreador);
        // users.email es NOT NULL+unique (ver migración create_users_table): '' es el
        // equivalente "sin email" que el schema permite, y sigue siendo falsy para el
        // chequeo if (!$destinatario->email) de OpenIssueNotificador::mail().
        $sinEmail = $this->usuario($deptoCreador, ['name' => 'Sin Email', 'email' => '']);

        $this->crearIssueVia($creador, [
            'departamento_destino_id' => $deptoDestino->id,
            'involucrados_ids' => [$sinEmail->id],
        ]);

        // Único destinatario posible (sinEmail) no tiene email: no se encola nada.
        Queue::assertNotPushed(SendHheeMailJob::class);
    }
}
