<?php

namespace App\Support;

use App\Models\Notificacion;
use App\Models\OpenIssue;
use App\Models\OpenIssueActualizacion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Única fuente de verdad de "quién se entera de qué" en el módulo Open
 * Issues. Solo campana (tabla notificaciones, tipo='open_issue'), SIN mail en
 * v1: no se crean Mailables ni Jobs. App\Support\OpenIssueFlujo SOLO llama a
 * estos métodos, nunca arma una notificación a mano.
 *
 * Destinatarios base = los INVOLUCRADOS del issue (que incluye siempre al
 * creador, ver §5.1 de la spec del módulo). NO se notifica a "todos los
 * usuarios del departamento destino" por el solo hecho de ser el destino
 * (sería una bomba de ruido en deptos grandes): si se los quiere notificar,
 * se los agrega como involucrados (feature que ya existe).
 *
 * Matriz EXACTA (ver §3.5 de la spec):
 * - crear()                -> notificarCreado: SOLO los involucrados NUEVOS.
 * - agregarInvolucrados()  -> notificarInvolucradosAgregados: SOLO los agregados en ese lote.
 * - quitarInvolucrado()    -> notificarInvolucradoQuitado: SOLO el quitado.
 * - agregarActualizacion() (comentario/cambio_estado) -> notificarActualizacion: TODOS los involucrados.
 * - cerrar()               -> notificarCierre: TODOS los involucrados.
 * - reabrir()               -> notificarReapertura: TODOS los involucrados.
 * - actualizar() (edición) -> nadie (no genera campana, es ruido).
 * - fila técnica 'apertura' -> nadie (la notificación de alta es la de "te involucraron").
 */
class OpenIssueNotificador
{
    public static function notificarCreado(OpenIssue $issue, User $actor, Collection $nuevos): void
    {
        self::campana($nuevos, $actor, $issue, "Te involucraron en el Open Issue #{$issue->id}: " . self::titulo($issue));
    }

    public static function notificarInvolucradosAgregados(OpenIssue $issue, User $actor, Collection $nuevos): void
    {
        self::campana($nuevos, $actor, $issue, "Te involucraron en el Open Issue #{$issue->id}: " . self::titulo($issue));
    }

    public static function notificarInvolucradoQuitado(OpenIssue $issue, User $actor, User $quitado): void
    {
        self::campana(collect([$quitado]), $actor, $issue, "Te quitaron del Open Issue #{$issue->id}: " . self::titulo($issue));
    }

    public static function notificarActualizacion(OpenIssue $issue, User $actor, OpenIssueActualizacion $actualizacion): void
    {
        if ($actualizacion->tipo === OpenIssueEstados::TIPO_CAMBIO_ESTADO) {
            $mensaje = "{$actor->name} pasó el Open Issue #{$issue->id} a " . OpenIssueEstados::label($actualizacion->estado_nuevo);
        } else {
            $mensaje = "{$actor->name} comentó en el Open Issue #{$issue->id}: " . self::titulo($issue);
        }

        self::campana(
            self::involucrados($issue),
            $actor,
            $issue,
            $mensaje,
            $actualizacion->estado_anterior,
            $actualizacion->estado_nuevo
        );
    }

    public static function notificarCierre(OpenIssue $issue, User $actor, string $estadoAnterior): void
    {
        self::campana(
            self::involucrados($issue),
            $actor,
            $issue,
            "{$actor->name} cerró el Open Issue #{$issue->id}: " . self::titulo($issue),
            $estadoAnterior,
            OpenIssueEstados::CERRADO
        );
    }

    public static function notificarReapertura(OpenIssue $issue, User $actor): void
    {
        self::campana(
            self::involucrados($issue),
            $actor,
            $issue,
            "{$actor->name} reabrió el Open Issue #{$issue->id}: " . self::titulo($issue),
            OpenIssueEstados::CERRADO,
            OpenIssueEstados::ABIERTO
        );
    }

    // =========================================================================
    // Helpers internos
    // =========================================================================

    private static function titulo(OpenIssue $issue): string
    {
        return Str::limit($issue->titulo, 120);
    }

    /**
     * Todos los involucrados del issue (incluye al creador, ver §5.1).
     */
    private static function involucrados(OpenIssue $issue): Collection
    {
        $ids = $issue->involucrados()->pluck('user_id');

        return User::whereIn('id', $ids)->get();
    }

    /**
     * Crea las filas de campana (tabla notificaciones, tipo='open_issue').
     * Dedupe por id de usuario y nunca notifica al actor.
     *
     * notificaciones.estado_anterior y estado_nuevo son NOT NULL: siempre hay
     * que mandar un string. Si el caller no tiene una transición real que
     * reportar (involucrado agregado/quitado, comentario sin cambio de
     * estado), se usa como fallback el estado ACTUAL del issue.
     *
     * $estadoAnterior/$estadoNuevo SIEMPRE llegan explícitos desde el caller
     * (capturados ANTES del update()), nunca con getOriginal(): es el mismo
     * bug que documenta App\Support\HheeNotificador.
     */
    private static function campana(
        Collection $destinatarios,
        User $actor,
        OpenIssue $issue,
        string $mensaje,
        ?string $estadoAnterior = null,
        ?string $estadoNuevo = null
    ): void {
        $anterior = $estadoAnterior ?? $issue->estado;
        $nuevo = $estadoNuevo ?? $issue->estado;

        self::destinatariosUnicos($destinatarios, $actor)->each(fn (User $d) => Notificacion::create([
            'orden_trabajo_id' => null,
            'solicitud_hhee_id' => null,
            'open_issue_id' => $issue->id,
            'tipo' => 'open_issue',
            'usuario_creador_id' => $d->id,      // destinatario (convención existente de la tabla)
            'usuario_mantenimiento_id' => $actor->id,  // actor
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
            'mensaje' => $mensaje,
            'leido' => false,
        ]));
    }

    private static function destinatariosUnicos(Collection $destinatarios, User $actor): Collection
    {
        return $destinatarios->filter()->unique('id')->reject(fn (User $u) => (int) $u->id === (int) $actor->id);
    }
}
