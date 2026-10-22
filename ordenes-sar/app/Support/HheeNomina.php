<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nómina de personal para la carga masiva de HHEE, leída de APP-RRHH
 * (SAR_RRHH2: employees + departments) en SOLO LECTURA por la conexión
 * config('hhee.nomina.conexion').
 *
 * Si la conexión no está configurada o falla, devuelve disponible=false y
 * una lista vacía: el formulario sigue funcionando con nombres escritos a mano.
 */
class HheeNomina
{
    private const CACHE_KEY = 'hhee:nomina:v1';

    /**
     * @return array{disponible: bool, empleados: array<int, array{legajo: ?string, nombre: string, puesto: ?string, departamento: string}>}
     */
    public static function obtener(): array
    {
        $conexion = config('hhee.nomina.conexion', 'rrhh');

        if (!self::conexionConfigurada($conexion)) {
            return ['disponible' => false, 'empleados' => []];
        }

        $minutos = max((int) config('hhee.nomina.cache_minutos', 10), 0);

        try {
            $empleados = $minutos > 0
                ? Cache::remember(self::CACHE_KEY, now()->addMinutes($minutos), fn () => self::leer($conexion))
                : self::leer($conexion);

            return ['disponible' => true, 'empleados' => $empleados];
        } catch (\Throwable $e) {
            Log::warning('HHEE: no se pudo leer la nómina de RRHH: ' . $e->getMessage());

            return ['disponible' => false, 'empleados' => []];
        }
    }

    public static function limpiarCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function conexionConfigurada(string $conexion): bool
    {
        $config = config("database.connections.$conexion");

        if (!is_array($config)) {
            return false;
        }

        // sqlite (tests) no tiene host; sqlsrv sí lo necesita.
        return ($config['driver'] ?? null) === 'sqlite' || !empty($config['host']);
    }

    private static function leer(string $conexion): array
    {
        $estadosActivos = config('hhee.nomina.estados_activos', ['Activo']);

        return DB::connection($conexion)
            ->table('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->whereIn('e.estado_actual', $estadosActivos)
            ->orderBy('d.nombre')
            ->orderBy('e.apellido')
            ->orderBy('e.nombre')
            ->get(['e.legajo', 'e.nombre', 'e.apellido', 'e.puesto', 'd.nombre as departamento'])
            ->map(fn ($fila) => [
                'legajo' => $fila->legajo !== null && $fila->legajo !== '' ? (string) $fila->legajo : null,
                'nombre' => self::nombreCompleto($fila->apellido, $fila->nombre),
                'puesto' => $fila->puesto ?: null,
                'departamento' => $fila->departamento ?: 'Sin sector',
            ])
            ->filter(fn ($empleado) => $empleado['nombre'] !== '')
            ->values()
            ->all();
    }

    private static function nombreCompleto(?string $apellido, ?string $nombre): string
    {
        $apellido = trim((string) $apellido);
        $nombre = trim((string) $nombre);

        if ($apellido !== '' && $nombre !== '') {
            return "{$apellido}, {$nombre}";
        }

        return $apellido !== '' ? $apellido : $nombre;
    }
}
