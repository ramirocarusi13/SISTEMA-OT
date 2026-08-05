<?php

namespace App\Http\Middleware;

use App\Support\Departamentos;
use Closure;
use Illuminate\Http\Request;

/**
 * El departamento Seguridad e Higiene (SyH) tiene acceso de SOLO LECTURA a
 * las OTs: puede ver las de seguridad (de cualquier departamento) y las
 * propias, pero no puede actuar sobre ninguna (crear, aprobar, asignar,
 * cambiar estado, finalizar, subir archivos, cambiar prioridad, eliminar,
 * comentar ni marcar mensajes como vistos).
 *
 * Este middleware centraliza el bloqueo para TODOS los endpoints de
 * escritura del módulo de OTs (ver el grupo de rutas en routes/api.php) en
 * vez de repetir el mismo chequeo en cada método de los controllers: un
 * endpoint de escritura nuevo queda cubierto por defecto agregándolo a ese
 * grupo, sin tener que acordarse de tocar cada controller.
 *
 * Si Departamentos::seguridadId() es null (departamento SyH inexistente en
 * esta base, como en desarrollo local) este middleware nunca bloquea a
 * nadie: el sistema se comporta exactamente igual que antes de este cambio.
 */
class BloquearEscrituraSeguridad
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && Departamentos::esSeguridad($user)) {
            return response()->json([
                'error' => 'El departamento de Seguridad e Higiene tiene acceso de solo lectura a las órdenes de trabajo.',
            ], 403);
        }

        return $next($request);
    }
}
