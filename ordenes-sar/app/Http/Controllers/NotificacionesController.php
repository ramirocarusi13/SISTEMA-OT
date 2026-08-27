<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Http\Request;

class NotificacionesController extends Controller
{
    // Método para obtener las notificaciones del usuario logueado
    public function index()
    {
        $usuarioLogueado = auth()->user();

        $notificaciones = Notificacion::where('usuario_creador_id', $usuarioLogueado->id)
            ->where('leido', false)
            ->with('usuarioMantenimiento') // Incluye la relación para obtener el nombre del usuario
            ->get()
            ->map(function ($notificacion) {
                // 'detalle' es un campo calculado (no columna): el texto de la
                // campana varía según 'tipo' (agregado por la migración de HHEE,
                // NOT NULL con default 'ot'). Para 'ot' se mantiene EXACTAMENTE
                // el mismo comportamiento que antes (no romper clientes
                // existentes); para 'hhee' se arma un texto propio a partir del
                // 'mensaje' ya armado por App\Support\HheeNotificador. 'tipo' y
                // 'solicitud_hhee_id' viajan igual en la respuesta por ser
                // columnas propias del modelo (sin necesidad de agregarlas acá).
                if ($notificacion->tipo === 'hhee') {
                    $notificacion->detalle = "Solicitud HHEE #{$notificacion->solicitud_hhee_id} – {$notificacion->mensaje}";
                } else {
                    $usuarioNombre = $notificacion->usuarioMantenimiento->name ?? 'Usuario desconocido';
                    $notificacion->detalle = "{$usuarioNombre} ha cambiado el estado de la orden de trabajo a {$notificacion->estado_nuevo}";
                }

                return $notificacion;
            });

        return response()->json($notificaciones);
    }

    // Método para crear una nueva notificación
    public function store(Request $request)
    {
        $request->validate([
            'orden_trabajo_id' => 'required|exists:ordenes_trabajo,id',
            'usuario_mantenimiento_id' => 'required|exists:users,id',
            'estado_anterior' => 'required|string',
            'estado_nuevo' => 'required|string',
            'mensaje' => 'nullable|string|max:255',
        ]);

        $usuarioLogueado = auth()->user();

        $mensaje = 'El usuario ' . $usuarioLogueado->name .
            ' cambió el estado de la orden de trabajo ' . $request->orden_trabajo_id .
            ' de "' . $request->estado_anterior . '" a "' . $request->estado_nuevo . '"';

        $notificacion = Notificacion::create([
            'orden_trabajo_id' => $request->orden_trabajo_id,
            'usuario_creador_id' => $usuarioLogueado->id,
            'usuario_mantenimiento_id' => $request->usuario_mantenimiento_id,
            'estado_anterior' => $request->estado_anterior,
            'estado_nuevo' => $request->estado_nuevo,
            'mensaje' => $mensaje,
            'leido' => false,
        ]);

        return response()->json(['message' => 'Notificación creada con éxito', 'notificacion' => $notificacion], 201);
    }
    public function marcarTodasLeidas(Request $request)
    {
        // Obtiene el usuario autenticado
        $user = auth()->user();

        // Marca todas las notificaciones del usuario como leídas
        Notificacion::where('usuario_creador_id', $user->id)->update(['leido' => true]);

        // Retorna una respuesta de éxito
        return response()->json(['message' => 'Todas las notificaciones marcadas como leídas'], 200);
    }



    public function enviarNotificacion(Request $request)
    {
        $request->validate([
            'orden_trabajo_id' => 'required|exists:ordenes_trabajo,id',
            'usuario_creador_id' => 'required|exists:users,id',
            'usuario_mantenimiento_id' => 'required|exists:users,id',
            'estado_nuevo' => 'required|string',
            'mensaje' => 'required|string|max:255',
        ]);

        $usuarioLogueado = auth()->user();
        $notificacion = Notificacion::create([
            'orden_trabajo_id' => $request->orden_trabajo_id,
            'usuario_creador_id' => $request->usuario_creador_id,
            'usuario_mantenimiento_id' => $request->usuario_mantenimiento_id,
            'estado_nuevo' => $request->estado_nuevo,
            'mensaje' => $request->mensaje,
            'leido' => false,
        ]);

        $mensaje = 'El usuario ' . $usuarioLogueado->name . ' ha enviado un mensaje en la orden ' . $request->orden_trabajo_id;

        return response()->json(['mensaje' => $mensaje]);
    }
}
