<?php

namespace App\Mail;

use App\Models\SolicitudHhee;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mail al solicitante: su solicitud HHEE cambió de estado (aprobada o
 * rechazada). Ver App\Support\HheeNotificador.
 */
class HheeEstadoCambiadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public SolicitudHhee $solicitud;
    public User $actor;
    public string $estadoNuevo;
    public bool $esContingencia;
    public ?string $motivoRechazo;

    public function __construct(
        SolicitudHhee $solicitud,
        User $actor,
        string $estadoNuevo,
        bool $esContingencia = false,
        ?string $motivoRechazo = null
    ) {
        $this->solicitud = $solicitud;
        $this->actor = $actor;
        $this->estadoNuevo = $estadoNuevo;
        $this->esContingencia = $esContingencia;
        $this->motivoRechazo = $motivoRechazo;
    }

    public function build()
    {
        return $this->subject("Solicitud HHEE #{$this->solicitud->id}: {$this->estadoNuevo}")
            ->view('emails.hhee_estado_cambiado')
            ->with([
                'solicitud' => $this->solicitud,
                'actor' => $this->actor,
                'estadoNuevo' => $this->estadoNuevo,
                'esContingencia' => $this->esContingencia,
                'motivoRechazo' => $this->motivoRechazo,
            ]);
    }
}
