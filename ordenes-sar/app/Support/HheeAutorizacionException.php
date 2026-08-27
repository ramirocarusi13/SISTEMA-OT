<?php

namespace App\Support;

/**
 * Excepción de autorización propia del módulo HHEE (distinta de una
 * ValidationException de estado/datos). El controller la atrapa
 * explícitamente y la traduce al formato 403 estándar del proyecto:
 * response()->json(['error' => $e->getMessage()], 403).
 *
 * Se usa desde App\Support\HheeFlujo para no repetir en cada acción
 * (enviar/aprobar/rechazar/anular/cargarHorasReales) la validación de "quién
 * puede ejecutar esto": queda centralizada ahí, y el controller solo necesita
 * un catch genérico para las 5 acciones.
 */
class HheeAutorizacionException extends \RuntimeException
{
}
