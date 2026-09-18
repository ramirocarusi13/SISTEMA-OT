<?php

namespace App\Support;

use App\Models\OpenIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance de Open Issues por permisos (espejo de App\Support\AlcanceHhee /
 * App\Support\AlcanceOrdenes): lectura amplia, escritura acotada.
 *
 * Lectura (aplicar()/puedeVer()): creador + involucrados + usuarios del
 * departamento destino + roles de config('open_issues.roles_ven_todo')
 * ('gerente', 'admin').
 *
 * Escritura (puedeEscribir()): involucrados (incluye siempre al creador,
 * ver §5.1 de la spec) + usuarios del departamento destino. Los 'gerente'
 * NO heredan escritura de su alcance global de lectura (mismo criterio que
 * App\Support\AlcanceOrdenes con Seguridad e Higiene: ve mucho, escribe
 * poco) -- es un desvío consciente del encargo original, documentado en la
 * spec del módulo (§5.5/§9).
 */
class AlcanceOpenIssues
{
    public static function veTodos(User $usuario): bool
    {
        return in_array($usuario->rol, config('open_issues.roles_ven_todo', ['gerente', 'admin']), true);
    }

    /**
     * Aplica el filtro de alcance de LECTURA sobre un query builder de OpenIssue.
     */
    public static function aplicar(Builder $query, User $usuario): Builder
    {
        if (self::veTodos($usuario)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($usuario) {
            // El OR por creador_id es redundante a propósito (el creador
            // siempre tiene fila en oi_involucrados, ver §5.1): blinda contra
            // cualquier issue corrupto que quedara sin esa fila.
            $q->where('creador_id', $usuario->id)
                ->orWhere('departamento_destino_id', $usuario->departamento_id)
                ->orWhereHas('involucrados', fn (Builder $qq) => $qq->where('user_id', $usuario->id));
        });
    }

    /**
     * Versión "por instancia" de aplicar(): true si el usuario puede VER este
     * issue puntual.
     */
    public static function puedeVer(User $usuario, OpenIssue $issue): bool
    {
        if (self::veTodos($usuario)) {
            return true;
        }

        if ((int) $issue->creador_id === (int) $usuario->id) {
            return true;
        }

        if ((int) $issue->departamento_destino_id === (int) $usuario->departamento_id) {
            return true;
        }

        return self::esInvolucrado($usuario, $issue);
    }

    /**
     * True si el usuario puede ESCRIBIR (comentar, adjuntar, cambiar estado,
     * cerrar, reabrir, agregar/quitar involucrados) sobre este issue.
     */
    public static function puedeEscribir(User $usuario, OpenIssue $issue): bool
    {
        if ((int) $issue->departamento_destino_id === (int) $usuario->departamento_id) {
            return true;
        }

        return self::esInvolucrado($usuario, $issue);
    }

    /**
     * Si 'involucrados' ya está cargada (show/detalle: eagerLoadDetalle()) se
     * resuelve en memoria y no dispara un EXISTS extra por cada uno de
     * puedeVer()/puedeEscribir()/flags()['es_involucrado'] (revisión #10). Si
     * no está cargada (ej. llamadas puntuales sin eager load), cae al EXISTS.
     */
    public static function esInvolucrado(User $usuario, OpenIssue $issue): bool
    {
        if ($issue->relationLoaded('involucrados')) {
            return $issue->involucrados->contains('user_id', $usuario->id);
        }

        return $issue->involucrados()->where('user_id', $usuario->id)->exists();
    }

    /**
     * Filtra $query a los issues donde $usuario PARTICIPA (tiene fila en
     * oi_involucrados). Usado por el filtro 'participo' del listado, por
     * GET /pendientes y por la tab "Donde participo" del front.
     */
    public static function aplicarParticipo(Builder $query, User $usuario): Builder
    {
        return $query->whereHas('involucrados', fn (Builder $q) => $q->where('user_id', $usuario->id));
    }
}
