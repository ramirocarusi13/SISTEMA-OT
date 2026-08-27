<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envío en cola de los mails de HHEE (clon del patrón de
 * App\Jobs\SendMensajeNotificacionJob). Genérico: recibe cualquier Mailable
 * ya armado (HheePendienteFirmaMail / HheeEstadoCambiadoMail) para no
 * necesitar un Job por cada tipo de mail del módulo.
 *
 * Una falla de mail NUNCA debe romper la transición de la solicitud: para
 * cuando este job corre, la transacción de HheeFlujo ya se confirmó
 * (QUEUE_CONNECTION=database), así que acá solo se loguea el error.
 */
class SendHheeMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $destinatarioEmail;
    protected Mailable $mailable;

    public function __construct(string $destinatarioEmail, Mailable $mailable)
    {
        $this->destinatarioEmail = $destinatarioEmail;
        $this->mailable = $mailable;
    }

    public function handle(): void
    {
        try {
            if ($this->destinatarioEmail) {
                Mail::to($this->destinatarioEmail)->send($this->mailable);
            }
        } catch (\Throwable $e) {
            Log::error('Error enviando mail de HHEE a ' . $this->destinatarioEmail . ': ' . $e->getMessage());
        }
    }
}
