<?php

namespace App\Mail;

use App\Models\SolicitudHhee;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mail a un aprobador: hay una solicitud HHEE pendiente de su firma
 * (nivel 1 o nivel final). Ver App\Support\HheeNotificador.
 */
class HheePendienteFirmaMail extends Mailable
{
    use Queueable, SerializesModels;

    public SolicitudHhee $solicitud;
    public User $actor;
    public int $nivel;

    public function __construct(SolicitudHhee $solicitud, User $actor, int $nivel)
    {
        $this->solicitud = $solicitud;
        $this->actor = $actor;
        $this->nivel = $nivel;
    }

    public function build()
    {
        return $this->subject("HHEE #{$this->solicitud->id} pendiente de tu firma")
            ->view('emails.hhee_pendiente_firma')
            ->with([
                'solicitud' => $this->solicitud,
                'actor' => $this->actor,
                'nivel' => $this->nivel,
                'etiquetaNivel' => $this->nivel === 1 ? 'Nivel 1' : 'Aprobación final',
            ]);
    }
}
