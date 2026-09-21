<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Sin sesión válida (token ausente, vencido o revocado): SIEMPRE 401 en
     * JSON. El comportamiento por defecto de Laravel redirige a route('login')
     * cuando el request no pide JSON, y acá el middleware 'json.response' corre
     * DESPUÉS de 'auth:api', así que un fetch sin header Accept recibía un 302
     * en vez de un 401. El front (ot-front/src/Utils/sesion.js) depende del 401
     * para mandar al usuario al login cuando se le venció el token.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        return response()->json(['message' => 'No autenticado. Iniciá sesión nuevamente.'], 401);
    }
}
