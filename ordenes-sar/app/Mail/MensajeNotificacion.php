<?php

namespace App\Mail;


use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MensajeNotificacion extends Mailable
{
    use Queueable, SerializesModels;

    public $mensaje;
    public $ordenTrabajo;
    public $usuario;

    public function __construct($mensaje, $ordenTrabajo, $usuario)
    {
        $this->mensaje = $mensaje;
        $this->ordenTrabajo = $ordenTrabajo;
        $this->usuario = $usuario;
    }

    public function build()
    {
        return $this->subject('Nuevo mensaje en la Orden de Trabajo')
            ->view('emails.mensaje_notificacion');
    }
}
