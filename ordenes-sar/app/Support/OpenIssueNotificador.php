<?php

namespace App\Support;

use App\Jobs\SendHheeMailJob;
use App\Mail\OpenIssueMail;
use App\Models\Notificacion;
use App\Models\OpenIssue;
use App\Models\OpenIssueActualizacion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Única fuente de verdad de "quién se entera de qué" en el módulo Open
 * Issues: campana (tabla notificaciones, tipo='open_issue') + mail en cola
 * (App\Mail\OpenIssueMail, mismo patrón que App\Support\HheeNotificador).
 * App\Support\OpenIssueFlujo SOLO llama a estos métodos, nunca arma una
 * notificación a mano.
 *
 * Destinatarios base = los INVOLUCRADOS del issue (que incluye siempre al
 * creador, ver §5.1 de la spec del módulo). NO se notifica a "todos los
 * usuarios del departamento destino" por el solo hecho de ser el destino
 * (sería una bomba de ruido en deptos grandes): si se los quiere notificar,
 * se los agrega como involucrados (feature que ya existe).
 *
 * Matriz EXACTA (ver §3.5 de la spec + config('open_issues.mail')):
 * - crear()                -> notificarCreado: SOLO los involucrados NUEVOS. Campana+mail (evento 'involucrado').
 * - agregarInvolucrados()  -> notificarInvolucradosAgregados: SOLO los agregados en ese lote. Campana+mail (evento 'involucrado').
 * - quitarInvolucrado()    -> notificarInvolucradoQuitado: SOLO el quitado. Campana SIN mail.
 * - agregarActualizacion() (comentario/cambio_estado) -> notificarActualizacion: TODOS los involucrados. Campana+mail (evento 'actualizacion' o 'cambio_estado').
 * - cerrar()               -> notificarCierre: TODOS los involucrados. Campana+mail (evento 'cierre').
 * - reabrir()               -> notificarReapertura: TODOS los involucrados. Campana+mail (evento 'reapertura').
 * - actualizar() (edición) -> nadie (no genera campana, es ruido).
 * - fila técnica 'apertura' -> nadie (la notificación de alta es la de "te involucraron").
 *
 * El mail SIEMPRE se despacha por App\Jobs\SendHheeMailJob (job genérico
 * reusado de HHEE, cola 'database'). Nunca se llama a Mail::send()/
 * Mail::to()->send() de forma síncrona.
 */
class OpenIssueNotificador
{
    public static function notificarCreado(OpenIssue $issue, User $actor, Collection $nuevos): void
    {
        self::campana($nuevos, $actor, $issue, "Te involucraron en el Open Issue #{$issue->id}: " . self::titulo($issue));
        self::mail($nuevos, $actor, 'involucrado', fn () => new OpenIssueMail($issue, $actor, 'involucrado'));
    }

    public static function notificarInvolucradosAgregados(OpenIssue $issue, User $actor, Collection $nuevos): void
    {
        self::campana($nuevos, $actor, $issue, "Te involucraron en el Open Issue #{$issue->id}: " . self::titulo($issue));
        self::mail($nuevos, $actor, 'involucrado', fn () => new OpenIssueMail($issue, $actor, 'involucrado'));
    }

    /**
     * Quitar un involucrado SOLO genera campana, nunca mail (no está en
     * config('open_issues.mail.eventos') y no se llama a self::mail() acá).
     */
    public static function notificarInvolucradoQuitado(OpenIssue $issue, User $actor, User $quitado): void
    {
        self::campana(collect([$quitado]), $actor, $issue, "Te quitaron del Open Issue #{$issue->id}: " . self::titulo($issue));
    }

    public static function notificarActualizacion(OpenIssue $issue, User $actor, OpenIssueActualizacion $actualizacion): void
    {
        $esCambioEstado = $actualizacion->tipo === OpenIssueEstados::TIPO_CAMBIO_ESTADO;

        if ($esCambioEstado) {
            $mensaje = "{$actor->name} pasó el Open Issue #{$issue->id} a " . OpenIssueEstados::label($actualizacion->estado_nuevo);
        } else {
            $mensaje = "{$actor->name} comentó en el Open Issue #{$issue->id}: " . self::titulo($issue);
        }

        $destinatarios = self::involucrados($issue);

        self::campana(
            $destinatarios,
            $actor,
            $issue,
            $mensaje,
            $actualizacion->estado_anterior,
            $actualizacion->estado_nuevo
        );

        if ($esCambioEstado) {
            $estadoNuevoLabel = OpenIssueEstados::label($actualizacion->estado_nuevo);

            self::mail($destinatarios, $actor, 'cambio_estado', fn () => new OpenIssueMail(
                $issue,
                $actor,
                'cambio_estado',
                null,
                $estadoNuevoLabel
            ));
        } else {
            $texto = self::textoParaMail($actualizacion);

            self::mail($destinatarios, $actor, 'actualizacion', fn () => new OpenIssueMail($issue, $actor, 'actualizacion', $texto));
        }
    }

    public static function notificarCierre(OpenIssue $issue, User $actor, string $estadoAnterior, ?string $texto = null): void
    {
        $destinatarios = self::involucrados($issue);

        self::campana(
            $destinatarios,
            $actor,
            $issue,
            "{$actor->name} cerró el Open Issue #{$issue->id}: " . self::titulo($issue),
            $estadoAnterior,
            OpenIssueEstados::CERRADO
        );

        self::mail($destinatarios, $actor, 'cierre', fn () => new OpenIssueMail($issue, $actor, 'cierre', $texto));
    }

    public static function notificarReapertura(OpenIssue $issue, User $actor): void
    {
        $destinatarios = self::involucrados($issue);

        self::campana(
            $destinatarios,
            $actor,
            $issue,
            "{$actor->name} reabrió el Open Issue #{$issue->id}: " . self::titulo($issue),
            OpenIssueEstados::CERRADO,
            OpenIssueEstados::ABIERTO
        );

        self::mail($destinatarios, $actor, 'reapertura', fn () => new OpenIssueMail($issue, $actor, 'reapertura'));
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
     * Texto del mail de una actualización sin cambio de estado: el comentario
     * si lo hay, o "Adjuntó un archivo: {nombre}" si solo vino un adjunto.
     */
    private static function textoParaMail(OpenIssueActualizacion $actualizacion): ?string
    {
        if ($actualizacion->texto) {
            return $actualizacion->texto;
        }

        if ($actualizacion->archivo) {
            return 'Adjuntó un archivo: ' . $actualizacion->archivo;
        }

        return null;
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

    /**
     * Despacha el mail a cada destinatario único (sin el actor, y solo si
     * tiene email cargado), SIEMPRE con ->afterCommit() (mismo motivo que
     * App\Support\HheeNotificador::mail()). No hace nada si el mail del
     * módulo está apagado o si $evento no está en la lista blanca de
     * config('open_issues.mail.eventos').
     */
    private static function mail(Collection $destinatarios, User $actor, string $evento, \Closure $fabricaMailable): void
    {
        if (!config('open_issues.mail.enabled')) {
            return;
        }

        if (!in_array($evento, config('open_issues.mail.eventos', []), true)) {
            return;
        }

        self::destinatariosUnicos($destinatarios, $actor)->each(function (User $destinatario) use ($fabricaMailable) {
            if (!$destinatario->email) {
                return;
            }

            SendHheeMailJob::dispatch($destinatario->email, $fabricaMailable($destinatario))->afterCommit();
        });
    }

    private static function destinatariosUnicos(Collection $destinatarios, User $actor): Collection
    {
        return $destinatarios->filter()->unique('id')->reject(fn (User $u) => (int) $u->id === (int) $actor->id);
    }
}
