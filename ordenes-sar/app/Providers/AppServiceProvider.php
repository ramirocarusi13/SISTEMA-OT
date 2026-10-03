<?php

namespace App\Providers;

use App\Models\Passport\Client;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Passport 13: los clientes existentes en `oauth_clients` tienen ids
        // enteros (bigIncrements). Passport 13 usa UUIDs por defecto; sin esto
        // no se encontrarían/crearían clientes con el esquema actual.
        Passport::$clientUuids = false;

        // Compatibilidad con el esquema legacy de `oauth_clients` (sin
        // columna grant_types). Ver App\Models\Passport\Client.
        Passport::useClientModel(Client::class);
    }
}
