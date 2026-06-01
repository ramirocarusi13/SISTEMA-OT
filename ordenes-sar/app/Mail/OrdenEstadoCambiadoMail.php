<?php

namespace App\Mail;



use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrdenEstadoCambiadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public $ordenTrabajo;
    public $estadoAnterior;
    public $estadoNuevo;
    public $mensaje;

    public $usuario;

public function __construct($ordenTrabajo, $estadoAnterior, $estadoNuevo, $usuario, $mensaje = null)
{
    $this->ordenTrabajo = $ordenTrabajo;
    $this->estadoAnterior = $estadoAnterior;
    $this->estadoNuevo = $estadoNuevo;
    $this->usuario = $usuario; // Asigna el usuario que realizó el cambio
    $this->mensaje = $mensaje;
}


public function build()
{
    return $this->subject('Estado de la Orden de Trabajo Cambiado')
                ->view('emails.estadoOrdenCambiado')
                ->with([
                    'ordenTrabajo' => $this->ordenTrabajo,
                    'estadoAnterior' => $this->estadoAnterior,
                    'estadoNuevo' => $this->estadoNuevo,
                    'usuario' => $this->usuario,
                ]);
}

}
