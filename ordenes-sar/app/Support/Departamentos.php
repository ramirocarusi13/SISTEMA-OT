<?php

namespace App\Support;

use App\Models\Departamento;
use App\Models\User;

/**
 * Resolución de departamentos "especiales" (Mantenimiento y Seguridad e
 * Higiene) sin números mágicos hardcodeados en los controllers.
 *
 * Orden de resolución para cada uno:
 *  1) id explícito por config/env (config('ot.departamentos.*')).
 *  2) si no está configurado, se resuelve por nombre contra la tabla
 *     `departamentos` y se memoiza en una propiedad estática (una sola query
 *     por request, aunque se consulte varias veces).
 *
 * IMPORTANTE: seguridadId() puede devolver null (departamento SyH inexistente
 * en esta base, como en desarrollo local, donde los departamentos son 1..8).
 * Todo el código que lo consume debe tratar null como "no aplica" para que el
 * sistema se comporte exactamente igual que antes de este cambio.
 */
class Departamentos
{
    private static ?int $mantenimientoId = null;
    private static bool $mantenimientoResuelto = false;

    private static ?int $seguridadId = null;
    private static bool $seguridadResuelto = false;

    /**
     * Id del departamento de Mantenimiento. Hoy siempre sale de config (default
     * histórico: 2), no hay resolución por nombre para no cambiar nada de lo
     * que ya funciona.
     */
    public static function mantenimientoId(): ?int
    {
        if (self::$mantenimientoResuelto) {
            return self::$mantenimientoId;
        }

        self::$mantenimientoResuelto = true;

        $configId = config('ot.departamentos.mantenimiento');

        return self::$mantenimientoId = ($configId !== null && $configId !== '')
            ? (int) $configId
            : null;
    }

    /**
     * Id del departamento de Seguridad e Higiene (SyH). Si no hay id
     * configurado, se resuelve por nombre. Si el departamento no existe,
     * devuelve null (y así queda memoizado para el resto del request: no se
     * reintenta la query en cada llamada).
     */
    public static function seguridadId(): ?int
    {
        if (self::$seguridadResuelto) {
            return self::$seguridadId;
        }

        self::$seguridadResuelto = true;

        $configId = config('ot.departamentos.seguridad');

        if ($configId !== null && $configId !== '') {
            return self::$seguridadId = (int) $configId;
        }

        $nombre = config('ot.departamentos.seguridad_nombre', 'SyH');

        $departamento = Departamento::where('nombre', $nombre)->first();

        return self::$seguridadId = $departamento ? (int) $departamento->id : null;
    }

    /**
     * True si el usuario pertenece al departamento de Mantenimiento.
     */
    public static function esMantenimiento(User $user): bool
    {
        $id = self::mantenimientoId();

        return $id !== null && (int) $user->departamento_id === $id;
    }

    /**
     * True si el usuario pertenece al departamento de Seguridad e Higiene
     * (SyH). Si seguridadId() es null (departamento inexistente en esta
     * base) esto siempre da false.
     */
    public static function esSeguridad(User $user): bool
    {
        $id = self::seguridadId();

        return $id !== null && (int) $user->departamento_id === $id;
    }

    /**
     * Limpia la memoización. Solo para tests: evita estado compartido entre
     * casos que corren en el mismo proceso PHP (ej. si un test cambia
     * config('ot.departamentos.seguridad') o inserta/borra el departamento).
     */
    public static function resetForTests(): void
    {
        self::$mantenimientoId = null;
        self::$mantenimientoResuelto = false;
        self::$seguridadId = null;
        self::$seguridadResuelto = false;
    }
}
