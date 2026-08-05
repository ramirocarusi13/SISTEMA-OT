<?php

namespace App\Support;

use App\Models\OrdenTrabajo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance de OTs por permisos, reutilizable entre los controllers que
 * necesitan decidir qué órdenes puede ver un usuario (MensajeController,
 * OrdenTrabajoController::index/show/getFotoFinalizada, ReporteController).
 * Reglas:
 * - Mantenimiento (App\Support\Departamentos::esMantenimiento) o rol =
 *   'admin' (legacy, no insertable hoy en el enum de users.rol, se deja el
 *   chequeo por compatibilidad con el resto del código) -> ve TODAS las OTs.
 * - Seguridad e Higiene / SyH (App\Support\Departamentos::esSeguridad) -> ve
 *   las OTs marcadas como de seguridad (es_seguridad = 1 o categoria =
 *   'seguridad') de CUALQUIER departamento, más las OTs propias (cuyo
 *   creador pertenece a SyH). No tiene alcance total: es un rol de solo
 *   lectura acotado a lo que le compete. Si el departamento SyH no existe en
 *   esta base (Departamentos::seguridadId() === null) esta rama nunca aplica.
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
        return Departamentos::esMantenimiento($user) || $user->rol === 'admin';
    }

    /**
     * True si el usuario pertenece al departamento de Seguridad e Higiene
     * (SyH): ve las OTs de seguridad de cualquier departamento + las propias,
     * pero no tiene alcance total (a diferencia de veTodosLosDepartamentos).
     */
    public static function esSeguridad(User $user): bool
    {
        return Departamentos::esSeguridad($user);
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

        if (self::esSeguridad($user)) {
            return $query->where(function (Builder $q) use ($departamentoId) {
                $q->where('es_seguridad', true)
                    ->orWhere('categoria', PrioridadOT::CAT_SEGURIDAD)
                    ->orWhereHas('creador', function ($qq) use ($departamentoId) {
                        $qq->where('departamento_id', $departamentoId);
                    });
            });
        }

        return $query->whereHas('creador', function ($q) use ($departamentoId) {
            $q->where('departamento_id', $departamentoId);
        });
    }

    /**
     * Versión "por instancia" de aplicar(): true si el usuario puede ver esta
     * OT puntual, dado su alcance (mismas reglas que aplicar(), pero evaluadas
     * en PHP sobre un modelo ya cargado en vez de en SQL). Pensada para
     * endpoints por id que hoy no filtran nada (show(), getFotoFinalizada()) y
     * para MensajeController::index(), que necesitan el mismo criterio sin
     * volver a golpear la base.
     *
     * Requiere que $orden tenga cargada la relación 'creador' (o que sea
     * null, en cuyo caso solo veTodosLosDepartamentos() puede dar acceso).
     */
    public static function puedeVer(User $user, ?OrdenTrabajo $orden): bool
    {
        if (self::veTodosLosDepartamentos($user)) {
            return true;
        }

        if (!$orden) {
            return false;
        }

        $departamentoCreador = $orden->creador ? (int) $orden->creador->departamento_id : null;

        if ($departamentoCreador !== null && $departamentoCreador === (int) $user->departamento_id) {
            return true;
        }

        if (self::esSeguridad($user)) {
            return (bool) $orden->es_seguridad || $orden->categoria === PrioridadOT::CAT_SEGURIDAD;
        }

        return false;
    }
}
