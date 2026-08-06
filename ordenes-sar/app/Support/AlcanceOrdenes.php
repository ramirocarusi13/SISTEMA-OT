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
 *   creador pertenece a SyH). El alcance de LECTURA es amplio, pero el de
 *   ESCRITURA (puedeEditar()) es más acotado: solo puede escribir en las OTs
 *   de su propio departamento; las que ve por estar marcadas de seguridad
 *   pero fueron creadas por otro departamento son de solo lectura. Si el
 *   departamento SyH no existe en esta base (Departamentos::seguridadId() ===
 *   null) esta rama nunca aplica.
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

    /**
     * True si el usuario puede EDITAR (crear descripciones, comentar, cambiar
     * estado, etc.) esta OT puntual. A diferencia de puedeVer(), que decide si
     * la OT entra en su alcance de lectura, esto decide si además puede
     * escribir sobre ella. La regla es POR ORDEN, no por usuario:
     * - Si ni siquiera puede verla -> false.
     * - Mantenimiento/admin (veTodosLosDepartamentos) -> true, como siempre.
     * - Seguridad e Higiene (SyH): si la OT es de su propio departamento
     *   (creador de SyH) tiene los mismos permisos que cualquier otro
     *   departamento (true); si la ve solo porque está marcada como de
     *   seguridad pero la creó OTRO departamento, es de solo lectura (false).
     * - Cualquier otro usuario/departamento -> true (la autorización fina por
     *   rol la siguen resolviendo los controllers como hoy; esto es solo la
     *   capa de alcance).
     *
     * Si $orden es null (p. ej. al crear una OT nueva, que todavía no existe)
     * siempre da true: crear una OT está permitido para cualquiera que llegue
     * hasta el controller (la validación de datos la hace el Form Request).
     *
     * Requiere que $orden tenga cargada la relación 'creador' (mismo
     * requisito que puedeVer()).
     */
    public static function puedeEditar(User $user, ?OrdenTrabajo $orden): bool
    {
        if (!$orden) {
            return true;
        }

        if (!self::puedeVer($user, $orden)) {
            return false;
        }

        if (self::veTodosLosDepartamentos($user)) {
            return true;
        }

        if (self::esSeguridad($user)) {
            $departamentoCreador = $orden->creador ? (int) $orden->creador->departamento_id : null;

            return $departamentoCreador !== null && $departamentoCreador === (int) $user->departamento_id;
        }

        return true;
    }
}
