<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // Este backend es SOLO API: nunca se redirige a una pantalla de login
        // propia (el login es del front React). Devolver null hace que la
        // falta de sesión termine en un 401 (ver Handler::unauthenticated()).
        //
        // Bug que había acá: el `if` sin `return` en la rama JSON hacía que la
        // función no devolviera nada teniendo declarado `?string`, y PHP 8 lo
        // convierte en un TypeError -> HTTP 500. Con un token vencido el front
        // recibía 500 (o un 302 si no mandaba Accept: application/json), nunca
        // un 401, y por eso no podía detectar la sesión vencida.
        return null;
    }
}
