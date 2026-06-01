<?php

namespace App\Http\Controllers;

use App\Jobs\SendMensajeNotificacionJob;
use App\Mail\MensajeNotificacion;
use App\Models\Mensaje;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MensajeController extends Controller
{
    public function index($id)
    {
        $mensajes = Mensaje::with('usuario')->where('orden_trabajo_id', $id)->orderBy('created_at')->get(); // Asegúrate de que tienes la relación definida en el modelo

        return response()->json($mensajes);
    }


    public function store(Request $request)
    {
        $request->validate([
            'orden_trabajo_id' => 'required|exists:ordenes_trabajo,id',
            'mensaje' => 'required|string',
        ]);

        $usuarioLogueado = auth()->user();
        $data = [
            'orden_trabajo_id' => $request->orden_trabajo_id,
            'mensaje' => $request->mensaje,
            'usuario_id' => $usuarioLogueado->id,
        ];

        // Crear el mensaje
        $mensaje = Mensaje::create($data);

        $ordenTrabajo = $mensaje->ordenTrabajo;
        $usuarioCreador = $ordenTrabajo->creador;
        $gerenteArea = $usuarioCreador->departamento->gerente;
        $gerenteMantenimiento = User::where('rol', 'gerente')->where('departamento_id', 2)->first();
        $usuarioMantenimiento = $ordenTrabajo->usuarioMantenimiento;

        $usuariosNotificar = collect([$gerenteArea, $gerenteMantenimiento, $usuarioMantenimiento, $usuarioCreador])
            ->filter()
            ->reject(fn($user) => $user->id === $usuarioLogueado->id);

        // Enviar el mensaje y enviar el correo en segundo plano

        foreach ($usuariosNotificar as $usuario) {
            dispatch(new SendMensajeNotificacionJob($mensaje, $ordenTrabajo, $usuarioLogueado, $usuario));
        }


        return response()->json($mensaje, 201);
    }
}
