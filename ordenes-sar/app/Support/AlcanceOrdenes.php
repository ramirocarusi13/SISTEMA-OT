<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance de OTs por permisos, reutilizable entre controllers que necesitan
 * decidir qué órdenes puede ver un usuario (hoy: MensajeController::index;
 * el mismo criterio también vive -inline- en OrdenTrabajoController::index
 * y ReporteController). Reglas:
 * - departamento_id = 2 (Mantenimiento) o rol = 'admin' (legacy, no
 *   insertable hoy en el enum de users.rol, se deja el chequeo por
 *   compatibilidad con el resto del código) -> ve TODAS las OTs.
 * - Cualquier otro usuario (gerente, group_leader, analista, team_member)
 *   -> solo ve las OTs cuyo creador pertenece a su propio departamento.
 */
class AlcanceOrdenes
{
    /**
     * True si el usuario ve todos los departamentos (no hace falta filtrar).
     */
    public static function veTodosLosDepartamentos(User $user): bool
    {
        return (int) $user->departamento_id === 2 || $user->rol === 'admin';
    }

    /**
     * Aplica el filtro de alcance sobre un query builder de OrdenTrabajo
     * (requiere que el modelo tenga la relación 'creador').
     */
    public static function aplicar(Builder $query, User $user): Builder
    {
        if (self::veTodosLosDepartamentos($user)) {
            return $query;
        }

        $departamentoId = $user->departamento_id;

        return $query->whereHas('creador', function ($q) use ($departamentoId) {
            $q->where('departamento_id', $departamentoId);
        });
    }
}
