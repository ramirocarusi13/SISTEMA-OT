<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppJob;
use App\Models\User;
use App\Support\WhatsApp;
use Illuminate\Console\Command;

/**
 * Manda un WhatsApp de prueba a un usuario, de forma SÍNCRONA (sin pasar por
 * la cola) para poder ver el resultado al toque desde la consola. Respeta
 * los mismos filtros que el enganche automático
 * (App\Support\WhatsAppNotificador::desdeNotificacion()): si el canal está
 * apagado o el usuario no está activo, lo dice y no manda nada.
 */
class WhatsAppProbarCommand extends Command
{
    protected $signature = 'whatsapp:probar
        {user : Id o email del usuario}
        {--texto= : Texto del mensaje de prueba}';

    protected $description = 'Manda un mensaje de WhatsApp de prueba de forma síncrona';

    public function handle(): int
    {
        if (!config('whatsapp.enabled')) {
            $this->warn('El canal WhatsApp está apagado (WHATSAPP_ENABLED=false). No se mandó nada.');

            return self::SUCCESS;
        }

        $user = $this->resolverUsuario($this->argument('user'));

        if (!$user) {
            $this->error("No se encontró un usuario con id/email '{$this->argument('user')}'.");

            return self::FAILURE;
        }

        if (!$user->whatsapp_activo) {
            $this->warn("El usuario #{$user->id} ({$user->email}) no tiene whatsapp_activo=true. No se mandó nada.");

            return self::SUCCESS;
        }

        $chatId = WhatsApp::chatIdDesdeCelular($user->celular);

        if (!$chatId) {
            $this->warn("El celular cargado ('{$user->celular}') no se pudo normalizar a un chatId válido. No se mandó nada.");

            return self::SUCCESS;
        }

        $texto = $this->option('texto') ?: 'Mensaje de prueba desde Sistema OT.';

        $this->line("Enviando a {$chatId}...");

        // Síncrono a propósito (dispatchSync, sin pasar por la cola): así el
        // comando ve el resultado real del POST a OpenWA al toque, en vez de
        // tener que ir a mirar el log/la tabla `jobs`.
        SendWhatsAppJob::dispatchSync($chatId, WhatsApp::textoConPrefijo($texto));

        $this->info('Listo. Revisá el log si el POST a OpenWA falló (se reintenta solo si va encolado; acá corrió una sola vez).');

        return self::SUCCESS;
    }

    private function resolverUsuario(string $identificador): ?User
    {
        if (is_numeric($identificador)) {
            return User::find((int) $identificador);
        }

        return User::where('email', $identificador)->first();
    }
}
