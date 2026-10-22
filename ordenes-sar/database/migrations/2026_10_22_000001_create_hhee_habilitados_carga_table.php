<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios habilitados A MANO a cargar solicitudes de HHEE aunque su
 * users.rol no esté en config('hhee.roles_pueden_cargar') ni tengan rol de
 * aprobación (casos puntuales, ej. un administrativo que carga por su sector).
 * Ver App\Support\HheePermisos::puedeCargar().
 *
 * Tabla aparte (y no una fila más en hhee_roles_aprobacion) para que "poder
 * cargar" nunca se confunda con "poder firmar": HheeAprobadores::esAprobador()
 * cuenta cualquier fila activa de esa tabla.
 */
return new class extends Migration
{
    private string $tabla = 'hhee_habilitados_carga';

    public function up(): void
    {
        Schema::create($this->tabla, function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'fk_hhee_hab_user')
                ->references('id')->on('users');

            $table->boolean('activo')->default(1);
            $table->string('observacion', 255)->nullable();

            $table->timestamps();

            $table->unique('user_id', 'uq_hhee_hab_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tabla);
    }
};
