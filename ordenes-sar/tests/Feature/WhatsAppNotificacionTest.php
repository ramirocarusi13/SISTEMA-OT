<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppJob;
use App\Models\Departamento;
use App\Models\Notificacion;
use App\Models\OpenIssue;
use App\Models\OrdenTrabajo;
use App\Models\SolicitudHhee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests del canal WhatsApp (App\Support\WhatsAppNotificador, App\Jobs\SendWhatsAppJob),
 * enganchado en App\Models\Notificacion::booted() (evento 'created'). Mismo
 * criterio de DB que HheeCircuitoTest/OpenIssuesCircuitoTest: sqlite
 * :memory: (ver phpunit.xml), nunca la base SQL Server real.
 *
 * Queue::fake() (en vez de dejar correr QUEUE_CONNECTION=sync de phpunit.xml)
 * porque WhatsAppNotificador::desdeNotificacion() despacha con
 * ->afterCommit(): bajo RefreshDatabase el test entero corre dentro de una
 * transacción que nunca se commitea, así que un dispatch real nunca
 * llegaría a ejecutarse. QueueFake intercepta el push() directo (no pasa
 * por Illuminate\Queue\Queue::shouldDispatchAfterCommit()), así que
 * Queue::assertPushed() funciona sin depender de ese commit.
 */
class WhatsAppNotificacionTest extends TestCase
{
    use RefreshDatabase;

    private function departamento(string $nombre = 'Producción'): Departamento
    {
        return Departamento::create(['nombre' => $nombre]);
    }

    private function usuarioWhatsapp(array $overrides = []): User
    {
        $depto = $this->departamento();

        return User::factory()->create(array_merge([
            'departamento_id' => $depto->id,
            'rol' => 'team_member',
            'turno' => 'Turno Mañana',
            'celular' => '11 5555-1234',
            'whatsapp_activo' => true,
        ], $overrides));
    }

    private function crearNotificacionOpenIssue(User $destinatario): Notificacion
    {
        $creadorIssue = User::factory()->create([
            'departamento_id' => $this->departamento('IT')->id,
            'rol' => 'team_member',
            'turno' => 'Turno Mañana',
        ]);

        $issue = OpenIssue::create([
            'titulo' => 'Funda 47A con hilo suelto',
            'departamento_destino_id' => $this->departamento('Calidad')->id,
            'creador_id' => $creadorIssue->id,
        ]);

        return Notificacion::create([
            'usuario_creador_id' => $destinatario->id,
            'usuario_mantenimiento_id' => $creadorIssue->id,
            'estado_anterior' => 'abierto',
            'estado_nuevo' => 'abierto',
            'mensaje' => 'Te involucraron en un nuevo issue.',
            'leido' => false,
            'tipo' => 'open_issue',
            'open_issue_id' => $issue->id,
        ]);
    }

    // =========================================================================
    // (a) enabled=true + usuario con celular y activo -> se despacha el job
    //     con el chatId correcto, y al ejecutarlo hace el POST esperado.
    // =========================================================================

    public function test_notificacion_con_canal_activo_despacha_job_con_chat_id_correcto(): void
    {
        config(['whatsapp.enabled' => true]);
        Queue::fake();

        $destinatario = $this->usuarioWhatsapp();
        $notificacion = $this->crearNotificacionOpenIssue($destinatario);

        Queue::assertPushed(SendWhatsAppJob::class, function (SendWhatsAppJob $job) use ($notificacion) {
            return $job->chatId === '5491155551234@c.us'
                && $job->texto === '[Sistema OT] ' . $notificacion->textoDetalle();
        });
    }

    public function test_job_hace_post_exacto_al_gateway_openwa_con_el_header_esperado(): void
    {
        config([
            'whatsapp.url' => 'http://localhost:2785',
            'whatsapp.session' => 'sistema-ot',
            'whatsapp.api_key' => 'clave-secreta-de-test',
        ]);

        Http::fake([
            'localhost:2785/*' => Http::response(['ok' => true], 200),
        ]);

        $job = new SendWhatsAppJob('5491155551234@c.us', '[Sistema OT] Te involucraron en un nuevo issue.');
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:2785/api/sessions/sistema-ot/messages/send-text'
                && $request->header('X-API-Key') === ['clave-secreta-de-test']
                && $request['chatId'] === '5491155551234@c.us'
                && $request['text'] === '[Sistema OT] Te involucraron en un nuevo issue.';
        });
    }

    // =========================================================================
    // (b) usuario sin whatsapp_activo -> no se despacha nada.
    // =========================================================================

    public function test_usuario_sin_whatsapp_activo_no_despacha_job(): void
    {
        config(['whatsapp.enabled' => true]);
        Queue::fake();

        $destinatario = $this->usuarioWhatsapp(['whatsapp_activo' => false]);
        $this->crearNotificacionOpenIssue($destinatario);

        Queue::assertNotPushed(SendWhatsAppJob::class);
    }

    // =========================================================================
    // (c) enabled=false -> no se despacha nada.
    // =========================================================================

    public function test_canal_apagado_no_despacha_job(): void
    {
        config(['whatsapp.enabled' => false]);
        Queue::fake();

        $destinatario = $this->usuarioWhatsapp();
        $this->crearNotificacionOpenIssue($destinatario);

        Queue::assertNotPushed(SendWhatsAppJob::class);
    }

    // =========================================================================
    // (d) textoDetalle() devuelve lo mismo que devolvía el controller para
    //     los tres tipos ('ot' | 'hhee' | 'open_issue'), ver
    //     NotificacionesController::index() y OpenIssuesCircuitoTest.
    // =========================================================================

    public function test_texto_detalle_coincide_con_el_texto_historico_del_controller_para_los_tres_tipos(): void
    {
        $depto = $this->departamento();
        $gl = $this->usuarioWhatsapp(['whatsapp_activo' => false]);
        $otroUsuario = $this->usuarioWhatsapp(['whatsapp_activo' => false]);

        $orden = OrdenTrabajo::create([
            'usuario_id' => $otroUsuario->id,
            'titulo' => 'Reparar impresora',
            'estado' => 'creada',
        ]);
        $notifOt = Notificacion::create([
            'orden_trabajo_id' => $orden->id,
            'usuario_creador_id' => $gl->id,
            'usuario_mantenimiento_id' => $otroUsuario->id,
            'estado_anterior' => 'creada',
            'estado_nuevo' => 'aprobada',
            'mensaje' => null,
            'leido' => false,
            'tipo' => 'ot',
        ]);
        $this->assertStringContainsString(
            'ha cambiado el estado de la orden de trabajo a aprobada',
            $notifOt->fresh(['usuarioMantenimiento'])->textoDetalle()
        );

        $solicitudHhee = SolicitudHhee::create([
            'solicitante_id' => $gl->id,
            'departamento_id' => $depto->id,
            'fecha_hhee' => now()->toDateString(),
        ]);
        $notifHhee = Notificacion::create([
            'usuario_creador_id' => $gl->id,
            'usuario_mantenimiento_id' => $otroUsuario->id,
            'estado_anterior' => 'pendiente_nivel1',
            'estado_nuevo' => 'pendiente_nivel1',
            'mensaje' => 'Tu solicitud está pendiente de tu firma',
            'leido' => false,
            'tipo' => 'hhee',
            'solicitud_hhee_id' => $solicitudHhee->id,
        ]);
        $this->assertSame(
            "Solicitud HHEE #{$solicitudHhee->id} – Tu solicitud está pendiente de tu firma",
            $notifHhee->textoDetalle()
        );

        $notifOi = $this->crearNotificacionOpenIssue($gl);
        $this->assertSame(
            "Open Issue #{$notifOi->open_issue_id} – Te involucraron en un nuevo issue.",
            $notifOi->textoDetalle()
        );
    }

    // =========================================================================
    // (e) el POST fallando con 500 lanza excepción (para que el job
    //     reintente) sin romper nada más.
    // =========================================================================

    public function test_job_lanza_excepcion_si_el_gateway_responde_error_para_que_se_reintente(): void
    {
        Http::fake([
            '*' => Http::response('Internal Server Error', 500),
        ]);

        $job = new SendWhatsAppJob('5491155551234@c.us', 'texto de prueba');

        $this->expectException(\RuntimeException::class);

        $job->handle();
    }
}
