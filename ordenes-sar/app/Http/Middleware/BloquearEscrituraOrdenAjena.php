<?php

namespace App\Http\Middleware;

use App\Models\OrdenTrabajo;
use App\Support\AlcanceOrdenes;
use Closure;
use Illuminate\Http\Request;

/**
 * Bloquea la escritura sobre una OT puntual cuando el usuario solo puede
 * verla en modo lectura (hoy, únicamente el caso de Seguridad e Higiene
 * mirando una OT ajena marcada como de seguridad; ver
 * App\Support\AlcanceOrdenes::puedeEditar()).
 *
 * A diferencia de la versión anterior (BloquearEscrituraSeguridad), que
 * bloqueaba TODA escritura para cualquier usuario de SyH, este middleware
 * evalúa la OT objetivo de la request: si el usuario puede escribir en esa
 * OT puntual (por ejemplo, porque la creó su propio departamento) la deja
 * pasar igual que a cualquier otro usuario.
 *
 * Resolución de la OT objetivo:
 * - Parámetro de ruta {id} o {ordenId} (la mayoría de las rutas de OTs usa
 *   {id}; agregar-archivos usa {ordenId}).
 * - Si no hay parámetro de ruta, se busca 'orden_trabajo_id' en el body
 *   (POST /mensajes, POST /notificaciones).
 * - Si no se encuentra ningún id (p. ej. POST /ordenes-trabajo, que crea una
 *   OT nueva) se deja pasar: crear una OT no requiere este chequeo, lo
 *   resuelve AlcanceOrdenes::puedeEditar(null) = true.
 * - Si hay id pero la OT no existe, se deja pasar también: que el controller
 *   responda el 404 correspondiente, no es responsabilidad de este
 *   middleware.
 */
class BloquearEscrituraOrdenAjena
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $ordenId = $request->route('id')
            ?? $request->route('ordenId')
            ?? $request->input('orden_trabajo_id');

        if (!$ordenId) {
            return $next($request);
        }

        $orden = OrdenTrabajo::with('creador')->find($ordenId);

        if (!$orden) {
            return $next($request);
        }

        if (!AlcanceOrdenes::puedeEditar($user, $orden)) {
            return response()->json([
                'error' => 'Esta orden es de otro departamento: tu área puede consultarla pero no modificarla.',
            ], 403);
        }

        return $next($request);
    }
}
