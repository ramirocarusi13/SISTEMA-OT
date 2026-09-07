<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Autenticación de los endpoints de integración servidor-a-servidor de HHEE
 * (APP-RRHH, ver routes/api.php -> prefix('hhee/integracion')): en vez de
 * auth:api/Passport, valida un secreto compartido en el header
 * 'X-Integracion-Key' contra config('hhee.integracion_key').
 *
 * - 503 si el servidor no tiene la key configurada (config('hhee.integracion_key')
 *   vacía/null): evita el caso peligroso de "cualquier request pasa" por un
 *   .env mal cargado, en vez de fallar en abierto.
 * - 401 si falta el header o no coincide con la key configurada.
 * - hash_equals() en vez de '===': comparación en tiempo constante, evita
 *   timing attacks para adivinar el secreto carácter a carácter.
 */
class VerificarIntegracionHhee
{
    public function handle(Request $request, Closure $next)
    {
        $keyConfigurada = config('hhee.integracion_key');

        if (empty($keyConfigurada)) {
            return response()->json([
                'error' => 'La integración de HHEE no está configurada en este servidor.',
            ], 503);
        }

        $keyRecibida = (string) $request->header('X-Integracion-Key', '');

        if ($keyRecibida === '' || !hash_equals($keyConfigurada, $keyRecibida)) {
            return response()->json(['error' => 'No autorizado.'], 401);
        }

        return $next($request);
    }
}
