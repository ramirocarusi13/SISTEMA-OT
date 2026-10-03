<?php

namespace App\Models\Passport;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Laravel\Passport\Client as PassportClient;

/**
 * Cliente OAuth de Passport 13 con compatibilidad para el esquema LEGACY de
 * `oauth_clients` (columnas `personal_access_client` / `password_client`, sin
 * columna `grant_types`) y IDs enteros (Passport::$clientUuids = false).
 *
 * Problema que resuelve: sin la columna `grant_types`, Passport 13 infiere los
 * grants y le asigna `client_credentials` a TODO cliente confidencial de
 * primera parte, incluido el "Personal Access Client" que crea
 * passport:install. Desde Passport 13.7.1 (fix del CVE-2026-39976),
 * TokenGuard rechaza (401) cualquier token cuyo `sub` (user id) coincida con
 * el id del cliente cuando el cliente tiene `client_credentials`. Resultado:
 * el usuario cuyo id == id del Personal Access Client (normalmente id 1)
 * quedaría sin poder usar la API. Ver laravel/passport#1912.
 *
 * Estas apps nunca usan el grant client_credentials (solo createToken()), así
 * que para filas legacy de clientes personal_access/password se quita ese
 * grant. Esto evita tener que migrar el esquema de `oauth_clients` en SQL
 * Server. Si en el futuro se aplica la migración oficial de Passport 13
 * (columna `grant_types` poblada), este override deja de tener efecto: el
 * valor de la columna se respeta tal cual.
 */
class Client extends PassportClient
{
    protected function grantTypes(): Attribute
    {
        $parent = parent::grantTypes();

        return Attribute::make(
            get: function (?string $value, array $attributes) use ($parent): array {
                $grantTypes = ($parent->get)($value, $attributes);

                if (! isset($value) && (! empty($attributes['personal_access_client']) || ! empty($attributes['password_client']))) {
                    $grantTypes = array_values(array_diff($grantTypes, ['client_credentials']));
                }

                return $grantTypes;
            },
        );
    }
}
