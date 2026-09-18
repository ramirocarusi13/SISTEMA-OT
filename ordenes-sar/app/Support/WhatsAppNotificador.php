<?php

namespace App\Support;

use App\Jobs\SendWhatsAppJob;
use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Reenvío por WhatsApp de las notificaciones (campana en `notificaciones`)
 * que ya se crean para OT/HHEE/Open Issues. Único punto de entrada:
 * App\Models\Notificacion::booted() (evento 'created') llama a
 * desdeNotificacion() por cada fila nueva, así OT/HHEE/Open Issues salen
 * por WhatsApp sin que cada notificador (HheeNotificador,
 * OpenIssueNotificador, NotificacionesController) tenga que acordarse de
 * llamar a este canal a mano.
 */
class WhatsAppNotificador
{
    /**
     * Destinatario de la campana = usuario_creador_id (así lo filtra
     * NotificacionesController::index(): "notificaciones DEL usuario
     * logueado"). Si el canal está apagado, el usuario no tiene celular
     * cargado o no activó el canal, no se manda nada (silencioso, salvo el
     * caso "activo pero celular inválido" que sí se loguea porque es una
     * config a medio cargar que conviene detectar).
     */
    public static function desdeNotificacion(Notificacion $notificacion): void
    {
        if (!config('whatsapp.enabled')) {
            return;
        }

        $destinatario = $notificacion->usuarioCreador;

        if (!$destinatario) {
            return;
        }

        if (!self::puedeEnviar($destinatario, logSiInvalido: true)) {
            return;
        }

        $chatId = WhatsApp::chatIdDesdeCelular($destinatario->celular);

        // self::puedeEnviar() ya validó que el chatId resuelve, pero se
        // vuelve a chequear acá por las dudas (defensivo, no debería pasar).
        if (!$chatId) {
            return;
        }

        SendWhatsAppJob::dispatch($chatId, WhatsApp::textoConPrefijo($notificacion->textoDetalle()))->afterCommit();
    }

    /**
     * Envío directo a un usuario puntual (usado por el comando
     * `whatsapp:probar`). Mismo filtro que desdeNotificacion(): respeta
     * 'enabled' y 'whatsapp_activo'/celular del destinatario. Devuelve false
     * sin lanzar excepción si no se pudo enviar (el comando decide cómo
     * informarlo).
     */
    public static function enviarA(User $user, string $texto): bool
    {
        if (!config('whatsapp.enabled')) {
            return false;
        }

        if (!self::puedeEnviar($user, logSiInvalido: true)) {
            return false;
        }

        $chatId = WhatsApp::chatIdDesdeCelular($user->celular);

        if (!$chatId) {
            return false;
        }

        SendWhatsAppJob::dispatchSync($chatId, WhatsApp::textoConPrefijo($texto));

        return true;
    }

    /**
     * True si el usuario tiene 'whatsapp_activo' y un celular que se puede
     * normalizar a chatId. Si está activo pero el celular no resuelve
     * (vacío, formato inválido, prefijo '15' sin área), se loguea: es una
     * configuración a medio cargar que el admin debería corregir, a
     * diferencia de "usuario simplemente no tiene WhatsApp habilitado".
     */
    private static function puedeEnviar(User $user, bool $logSiInvalido): bool
    {
        if (!$user->whatsapp_activo) {
            return false;
        }

        if (WhatsApp::chatIdDesdeCelular($user->celular)) {
            return true;
        }

        if ($logSiInvalido) {
            Log::info("WhatsApp activo pero celular inválido/vacío para el usuario #{$user->id} ({$user->email}).");
        }

        return false;
    }
}
