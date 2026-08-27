<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed inicial de hhee_roles_aprobacion.
 *
 * Carga como rol 'gerente_area' (con su departamento_id, es decir scope local a su sector) a todos
 * los usuarios existentes con users.rol = 'gerente'. La tabla `users` no tiene ningún flag de
 * activo/estado (columnas: id, name, email, email_verified_at, password, departamento_id, rol, turno,
 * remember_token, timestamps) por lo que se toman todos los usuarios con ese rol, sin filtro adicional.
 *
 * Los roles gerencia_general, rrhh, presidencia y contingencia son de alcance más amplio
 * (normalmente global, departamento_id NULL) y corresponden a personas puntuales que no se pueden
 * inferir de users.rol; se cargan a mano (o con un seeder aparte) cuando se tenga la lista real
 * de personas para cada uno de esos roles.
 *
 * Idempotente: no duplica filas si se corre más de una vez (usa updateOrInsert sobre la misma
 * combinación que valida el UNIQUE uq_hhee_rol_user_rol_depto).
 *
 * Se corre manualmente, NO está registrado en DatabaseSeeder para no pisar otros seeds:
 *   php artisan db:seed --class=HheeRolesSeeder
 */
class HheeRolesSeeder extends Seeder
{
    public function run(): void
    {
        $gerentes = User::where('rol', 'gerente')->get(['id', 'departamento_id']);

        foreach ($gerentes as $gerente) {
            $clave = [
                'user_id' => $gerente->id,
                'rol' => 'gerente_area',
                'departamento_id' => $gerente->departamento_id,
            ];

            $yaExiste = DB::table('hhee_roles_aprobacion')->where($clave)->exists();

            if ($yaExiste) {
                // Ya cargado en una corrida anterior: solo se reactiva si estaba desactivado,
                // sin tocar created_at.
                DB::table('hhee_roles_aprobacion')->where($clave)->update([
                    'activo' => 1,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('hhee_roles_aprobacion')->insert(array_merge($clave, [
                'activo' => 1,
                'observacion' => 'Alta automática desde HheeRolesSeeder (users.rol = gerente)',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command?->info(
            "HheeRolesSeeder: {$gerentes->count()} usuario(s) con rol 'gerente' cargados como 'gerente_area'."
        );
    }
}
