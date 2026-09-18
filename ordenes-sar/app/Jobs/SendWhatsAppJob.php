<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envío en cola de un mensaje de WhatsApp vía el gateway OpenWA (self-hosted).
 * Clon del patrón de App\Jobs\SendHheeMailJob, pero acá SÍ reintenta: a
 * diferencia del mail (donde una falla se loguea y listo, porque para cuando
 * el job corre la notificación ya existe igual), un POST fallido a OpenWA
 * suele ser transitorio (gateway reiniciando, sesión de WhatsApp
 * reconectando), así que vale la pena reintentar unas veces antes de
 * darse por vencido.
 *
 * Contrato del gateway (repo rmyndharis/OpenWA):
 *   POST {WHATSAPP_URL}/api/sessions/{WHATSAPP_SESSION}/messages/send-text
 *   Headers: Content-Type: application/json, X-API-Key: {WHATSAPP_API_KEY}
 *   Body: {"chatId": "...", "text": "..."}
 */
class SendWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Reintentos ante falla (gateway caído/timeout/respuesta no exitosa).
     */
    public $tries = 3;

    /**
     * Backoff creciente entre reintentos (segundos): 30s, 2min, 10min.
     */
    public $backoff = [30, 120, 600];

    public string $chatId;
    public string $texto;

    public function __construct(string $chatId, string $texto)
    {
        $this->chatId = $chatId;
        $this->texto = $texto;
    }

    public function handle(): void
    {
        $url = rtrim(config('whatsapp.url'), '/') . '/api/sessions/' . config('whatsapp.session') . '/messages/send-text';

        $respuesta = Http::withHeaders([
            // NUNCA loguear este header: es el secreto del gateway.
            'X-API-Key' => config('whatsapp.api_key'),
        ])
            ->timeout((int) config('whatsapp.timeout', 10))
            ->post($url, [
                'chatId' => $this->chatId,
                'text' => $this->texto,
            ]);

        if (!$respuesta->successful()) {
            $cuerpoRecortado = Str::limit($respuesta->body(), 300);

            Log::error("Error enviando WhatsApp a {$this->chatId}: HTTP {$respuesta->status()} - {$cuerpoRecortado}");

            // Se relanza para que el job se reintente (ver $tries/$backoff).
            throw new \RuntimeException("OpenWA respondió HTTP {$respuesta->status()} al enviar WhatsApp a {$this->chatId}");
        }
    }

    /**
     * Se agotaron los reintentos: solo queda dejar constancia en el log, el
     * mensaje de WhatsApp se pierde (igual que un mail que nunca se pudo
     * entregar). Nunca debe romper nada más: para cuando este job corre, la
     * notificación/campana original ya existe en la DB.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("WhatsApp a {$this->chatId} agotó los reintentos: " . ($exception?->getMessage() ?? 'sin excepción'));
    }
}
