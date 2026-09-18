<?php

namespace App\Support;

/**
 * Excepción de autorización propia del módulo Open Issues (distinta de una
 * ValidationException de estado/datos). El controller la atrapa
 * explícitamente y la traduce al formato 403 estándar del proyecto:
 * response()->json(['error' => $e->getMessage()], 403).
 *
 * Se usa desde App\Support\OpenIssueFlujo para no repetir en cada acción de
 * escritura (actualizar/agregarActualizacion/cerrar/reabrir/
 * agregarInvolucrados/quitarInvolucrado) la validación de "quién puede
 * ejecutar esto": queda centralizada ahí, y el controller solo necesita un
 * catch genérico.
 */
class OpenIssueAutorizacionException extends \RuntimeException
{
}
