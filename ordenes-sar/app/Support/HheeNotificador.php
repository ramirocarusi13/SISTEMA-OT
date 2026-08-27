<?php

namespace App\Support;

use App\Jobs\SendHheeMailJob;
use App\Mail\HheeEstadoCambiadoMail;
use App\Mail\HheePendienteFirmaMail;
use App\Models\AprobacionHhee;
use App\Models\HheeRolAprobacion;
use App\Models\Notificacion;
use App\Models\SolicitudHhee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Notificaciones (campana en `notificaciones` + mail en cola) del módulo
 * HHEE. Única fuente de verdad de "quién se entera de qué" (ver spec del
 * módulo); App\Support\HheeFlujo SOLO llama a estos métodos, nunca arma una
 * notificación a mano.
 *
 * Matriz de notificaciones:
 * - enviar          -> campana+mail a aprobadores N1 del depto; campana al solicitante.
 * - aprobar N1      -> campana+mail a aprobadores N2; campana al solicitante.
 * - aprobar final   -> campana+mail al solicitante; campana al aprobador N1.
 * - rechazar        -> campana+mail al solicitante; campana a quienes ya firmaron.
 * - anular          -> campana a aprobadores del nivel pendiente.
 * - cerrada (horas reales) -> campana a RRHH (rol rrhh).
 *
 * El mail SIEMPRE se despacha por App\Jobs\SendHheeMailJob (cola 'database').
 * Nunca se llama a Mail::send()/Mail::to()->send() de forma síncrona.
 */
class HheeNotificador
{
    public static function notificarEnviada(SolicitudHhee $solicitud, User $actor, string $estadoAnterior): void
    {
        $aprobadoresN1 = HheeAprobadores::aprobadoresDeNivel(1, $solicitud->departamento_id);

        self::campana($aprobadoresN1, $actor, $solicitud, self::mensajePendiente($solicitud, 1), $estadoAnterior);
        self::mail($aprobadoresN1, $actor, fn () => new HheePendienteFirmaMail($solicitud, $actor, 1));

        self::campana(
            collect([$solicitud->solicitante]),
            $actor,
            $solicitud,
            "Tu solicitud HHEE #{$solicitud->id} fue enviada y está pendiente de aprobación.",
            $estadoAnterior
        );
    }

    public static function notificarAprobadaNivel1(SolicitudHhee $solicitud, User $actor, bool $esContingencia, string $estadoAnterior): void
    {
        $aprobadoresN2 = HheeAprobadores::aprobadoresDeNivel(2, $solicitud->departamento_id);

        self::campana($aprobadoresN2, $actor, $solicitud, self::mensajePendiente($solicitud, 2), $estadoAnterior, $esContingencia);
        self::mail($aprobadoresN2, $actor, fn () => new HheePendienteFirmaMail($solicitud, $actor, 2));

        self::campana(
            collect([$solicitud->solicitante]),
            $actor,
            $solicitud,
            "Tu solicitud HHEE #{$solicitud->id} fue aprobada en nivel 1 y sigue en aprobación final.",
            $estadoAnterior,
            $esContingencia
        );
    }

    public static function notificarAprobadaFinal(SolicitudHhee $solicitud, User $actor, bool $esContingencia, string $estadoAnterior): void
    {
        $solicitante = collect([$solicitud->solicitante]);

        self::campana(
            $solicitante,
            $actor,
            $solicitud,
            "Tu solicitud HHEE #{$solicitud->id} fue aprobada. Ya podés cargar las horas reales.",
            $estadoAnterior,
            $esContingencia
        );
        self::mail($solicitante, $actor, fn () => new HheeEstadoCambiadoMail($solicitud, $actor, HheeEstados::APROBADA, $esContingencia));

        $aprobadorNivel1 = self::aprobadorQueFirmoNivel($solicitud, 1);

        if ($aprobadorNivel1) {
            self::campana(
                collect([$aprobadorNivel1]),
                $actor,
                $solicitud,
                "La solicitud HHEE #{$solicitud->id} que aprobaste en nivel 1 fue aprobada en forma final.",
                $estadoAnterior
            );
        }
    }

    public static function notificarRechazada(SolicitudHhee $solicitud, User $actor, bool $esContingencia, string $estadoAnterior): void
    {
        $solicitante = collect([$solicitud->solicitante]);

        self::campana(
            $solicitante,
            $actor,
            $solicitud,
            "Tu solicitud HHEE #{$solicitud->id} fue rechazada: {$solicitud->motivo_rechazo}",
            $estadoAnterior,
            $esContingencia
        );
        self::mail($solicitante, $actor, fn () => new HheeEstadoCambiadoMail(
            $solicitud,
            $actor,
            HheeEstados::RECHAZADA,
            $esContingencia,
            $solicitud->motivo_rechazo
        ));

        $yaFirmaron = self::aprobadoresQueYaFirmaron($solicitud);
        self::campana(
            $yaFirmaron,
            $actor,
            $solicitud,
            "La solicitud HHEE #{$solicitud->id} que firmaste fue rechazada.",
            $estadoAnterior,
            $esContingencia
        );
    }

    public static function notificarAnulada(SolicitudHhee $solicitud, User $actor, string $estadoAnterior): void
    {
        $nivelPendiente = match ($estadoAnterior) {
            HheeEstados::PENDIENTE_NIVEL1 => 1,
            HheeEstados::PENDIENTE_FINAL => 2,
            default => null,
        };

        if ($nivelPendiente === null) {
            // Se anuló desde borrador o aprobada: no había ninguna firma
            // pendiente esperando por esta solicitud, nadie que avisar.
            return;
        }

        $aprobadores = HheeAprobadores::aprobadoresDeNivel($nivelPendiente, $solicitud->departamento_id);

        self::campana(
            $aprobadores,
            $actor,
            $solicitud,
            "La solicitud HHEE #{$solicitud->id}, pendiente de tu firma, fue anulada por el solicitante.",
            $estadoAnterior
        );
    }

    public static function notificarCerrada(SolicitudHhee $solicitud, User $actor, string $estadoAnterior): void
    {
        $rolCierre = config('hhee.rol_notificacion_cierre', 'rrhh');

        $idsDestinatarios = HheeRolAprobacion::where('rol', $rolCierre)->where('activo', true)->pluck('user_id')->unique();
        $usuariosDestino = User::whereIn('id', $idsDestinatarios)->get();

        self::campana(
            $usuariosDestino,
            $actor,
            $solicitud,
            "Se cargaron las horas reales de la solicitud HHEE #{$solicitud->id} y quedó cerrada.",
            $estadoAnterior
        );
    }

    // =========================================================================
    // Helpers internos
    // =========================================================================

    private static function mensajePendiente(SolicitudHhee $solicitud, int $nivel): string
    {
        $etiquetaNivel = $nivel === 1 ? 'nivel 1' : 'aprobación final';

        return "La solicitud HHEE #{$solicitud->id} está pendiente de tu firma ({$etiquetaNivel}).";
    }

    private static function aprobadorQueFirmoNivel(SolicitudHhee $solicitud, int $nivel): ?User
    {
        $aprobacion = AprobacionHhee::where('solicitud_id', $solicitud->id)->where('nivel', $nivel)->first();

        return $aprobacion && $aprobacion->aprobador_id ? User::find($aprobacion->aprobador_id) : null;
    }

    private static function aprobadoresQueYaFirmaron(SolicitudHhee $solicitud): Collection
    {
        $ids = AprobacionHhee::where('solicitud_id', $solicitud->id)
            ->where('estado', 'aprobada')
            ->pluck('aprobador_id')
            ->filter()
            ->unique();

        return User::whereIn('id', $ids)->get();
    }

    /**
     * Crea las filas de campana (tabla notificaciones, tipo='hhee'). Deduplica
     * destinatarios y nunca notifica al actor (quien ejecutó la acción). Si
     * $esContingencia, agrega el sufijo "(firma por contingencia)" al mensaje.
     *
     * $estadoAnterior SIEMPRE se recibe explícito del caller (HheeFlujo, que lo
     * capturó ANTES de mutar $solicitud->estado): usar
     * $solicitud->getOriginal('estado') acá sería un bug, porque para cuando
     * HheeFlujo llama a este notificador ya hizo ->save() de la solicitud, y
     * getOriginal() después de save() devuelve los valores YA sincronizados
     * (o sea, iguales al estado nuevo), no el estado real anterior.
     */
    private static function campana(
        Collection $destinatarios,
        User $actor,
        SolicitudHhee $solicitud,
        string $mensaje,
        string $estadoAnterior,
        bool $esContingencia = false
    ): void {
        $mensajeFinal = $esContingencia ? "{$mensaje} (firma por contingencia)" : $mensaje;

        self::destinatariosUnicos($destinatarios, $actor)->each(function (User $destinatario) use ($actor, $solicitud, $mensajeFinal, $estadoAnterior) {
            Notificacion::create([
                'orden_trabajo_id' => null,
                'solicitud_hhee_id' => $solicitud->id,
                'tipo' => 'hhee',
                'usuario_creador_id' => $destinatario->id,
                'usuario_mantenimiento_id' => $actor->id,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $solicitud->estado,
                'mensaje' => $mensajeFinal,
                'leido' => false,
            ]);
        });
    }

    /**
     * Despacha el mail a cada destinatario único (sin el actor, y solo si
     * tiene email cargado). SIEMPRE con ->afterCommit(): si en el futuro
     * cambia QUEUE_CONNECTION a un driver que no sea síncrono con la
     * transacción (hoy 'database', que ya solo hace visible el job al
     * commitear), este flag blinda igual contra dispatchar el mail de una
     * transacción que después terminó haciendo rollback.
     */
    private static function mail(Collection $destinatarios, User $actor, \Closure $fabricaMailable): void
    {
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
