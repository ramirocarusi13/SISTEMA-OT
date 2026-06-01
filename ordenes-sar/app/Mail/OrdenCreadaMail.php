<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrdenCreadaMail extends Mailable
{
    public $ordenTrabajo;
    public $usuarioCreador;
    public $usuario;

    public function __construct($ordenTrabajo, $usuarioCreador)
    {
        $this->ordenTrabajo = $ordenTrabajo; // La orden de trabajo
        $this->usuarioCreador = $usuarioCreador; 
        // El usuario que creó la orden
    }

    public function build()
    {
        return $this->view('emails.orden_creada')
                    ->with([
                        'ordenTrabajo' => $this->ordenTrabajo,
                        'usuarioCreador' => $this->usuarioCreador,
                    ]);
    }
}
