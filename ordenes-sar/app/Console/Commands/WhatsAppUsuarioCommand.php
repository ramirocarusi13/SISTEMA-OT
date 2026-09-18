<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\WhatsApp;
use Illuminate\Console\Command;

/**
 * Carga/activa el celular de WhatsApp de un usuario. El celular y el flag
 * 'whatsapp_activo' son individuales (ver migración
 * 2026_09_18_000005_add_celular_whatsapp_to_users_table.php): no hay UI para
 * esto todavía, se administra por consola.
 */
class WhatsAppUsuarioCommand extends Command
{
    protected $signature = 'whatsapp:usuario
        {user : Id o email del usuario}
        {celular? : Nuevo celular a cargar (si se omite, no se toca)}
        {--activar : Activa el canal WhatsApp para este usuario}
        {--desactivar : Desactiva el canal WhatsApp para este usuario}';

    protected $description = 'Muestra y/o configura el celular y el flag whatsapp_activo de un usuario';

    public function handle(): int
    {
        $user = $this->resolverUsuario($this->argument('user'));

        if (!$user) {
            $this->error("No se encontró un usuario con id/email '{$this->argument('user')}'.");

            return self::FAILURE;
        }

        if ($this->option('activar') && $this->option('desactivar')) {
            $this->error('No se puede usar --activar y --desactivar al mismo tiempo.');

            return self::FAILURE;
        }

        $celular = $this->argument('celular');

        if ($celular !== null) {
            $user->celular = $celular;
        }

        if ($this->option('activar')) {
            $user->whatsapp_activo = true;
        } elseif ($this->option('desactivar')) {
            $user->whatsapp_activo = false;
        }

        $user->save();

        $chatId = WhatsApp::chatIdDesdeCelular($user->celular);

        $this->info("Usuario #{$user->id} ({$user->email})");
        $this->line("  celular:         " . ($user->celular ?? '(sin cargar)'));
        $this->line("  whatsapp_activo: " . ($user->whatsapp_activo ? 'sí' : 'no'));
        $this->line("  chatId normalizado: " . ($chatId ?? '(no se pudo resolver un chatId válido)'));

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
