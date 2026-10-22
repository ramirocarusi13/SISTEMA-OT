<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién puede CARGAR (crear/editar/enviar) solicitudes de HHEE: de group
 * leader para arriba. Ver config('hhee.roles_pueden_cargar').
 *
 * Firmar sigue siendo exclusivo de App\Support\HheeAprobadores: esta clase
 * solo decide quién arma solicitudes.
 */
class HheePermisos
{
    public static function puedeCargar(User $usuario): bool
    {
        if (in_array($usuario->rol, config('hhee.roles_pueden_cargar', []), true)) {
            return true;
        }

        if (HheeAprobadores::esAprobador($usuario)) {
            return true;
        }

        return self::habilitadoManualmente($usuario);
    }

    private static function habilitadoManualmente(User $usuario): bool
    {
        // Tolerante a que la migración todavía no haya corrido en el entorno:
        // sin la tabla, nadie está habilitado a mano (no rompe el módulo).
        if (!Schema::hasTable('hhee_habilitados_carga')) {
            return false;
        }

        return DB::table('hhee_habilitados_carga')
            ->where('user_id', $usuario->id)
            ->where('activo', true)
            ->exists();
    }
}
