<?php

namespace App\Http\Controllers;

use App\Jobs\SendMensajeNotificacionJob;
use App\Mail\MensajeNotificacion;
use App\Models\Mensaje;
use App\Models\MensajeLectura;
use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Support\AlcanceOrdenes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MensajeController extends Controller
{
    public function index($id)
    {
        // Se busca la orden (con su creador) para poder aplicar el mismo alcance
        // que usa OrdenTrabajoController::index()/show() (App\Support\AlcanceOrdenes):
        // sin esto, cualquier usuario autenticado podía leer el chat de cualquier
        // OT solo pasando su id (secuencial), sin importar su departamento.
        $orden = OrdenTrabajo::with('creador')->find($id);

        if (!$orden) {
            return response()->json(['error' => 'Orden de trabajo no encontrada'], 404);
        }

        $userLogueado = auth()->user();

        if (!AlcanceOrdenes::puedeVer($userLogueado, $orden)) {
            return response()->json(['error' => 'No tiene permisos para ver los mensajes de esta orden'], 403);
        }

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

    public function marcarVisto($id)
    {
        $existe = \App\Models\OrdenTrabajo::where('id', $id)->exists();
        if (!$existe) {
            return response()->json(['error' => 'Orden de trabajo no encontrada'], 404);
        }

        try {
            MensajeLectura::updateOrCreate(
                ['orden_trabajo_id' => $id, 'user_id' => auth()->id()],
                ['last_read_at' => now()]
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // Requests concurrentes pueden chocar contra el unique (orden, usuario): el visto ya quedó registrado
            MensajeLectura::where('orden_trabajo_id', $id)
                ->where('user_id', auth()->id())
                ->update(['last_read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
