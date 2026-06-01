<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Mail\MensajeNotificacion;
use Illuminate\Support\Facades\Mail;

class SendMensajeNotificacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $mensaje;
    protected $ordenTrabajo;
    protected $usuarioLogueado;
    protected $usuario;

    public function __construct($mensaje, $ordenTrabajo, $usuarioLogueado, $usuario)
    {
        $this->mensaje = $mensaje;
        $this->ordenTrabajo = $ordenTrabajo;
        $this->usuarioLogueado = $usuarioLogueado;
        $this->usuario = $usuario;
    }

    public function handle()
    {
        if ($this->usuario->email) {
            Mail::to($this->usuario->email)->send(new MensajeNotificacion($this->mensaje, $this->ordenTrabajo, $this->usuarioLogueado));
        }
    }
}
