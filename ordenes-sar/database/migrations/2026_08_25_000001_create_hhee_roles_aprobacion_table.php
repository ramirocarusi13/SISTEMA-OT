<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHheeRolesAprobacionTable extends Migration
{
    private string $tabla = 'hhee_roles_aprobacion';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->tabla, function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'fk_hhee_rol_user')
                ->references('id')->on('users');

            // Valores esperados: jefe, gerente_area, gerencia_general, rrhh, presidencia, contingencia
            // (validado en la app, sin CHECK constraint en la base).
            $table->string('rol', 30);

            // NULL = alcance global (aplica a todos los departamentos, ej: rrhh, presidencia, contingencia).
            $table->unsignedBigInteger('departamento_id')->nullable();
            $table->foreign('departamento_id', 'fk_hhee_rol_departamento')
                ->references('id')->on('departamentos');

            $table->boolean('activo')->default(1);
            $table->string('observacion', 255)->nullable();

            $table->timestamps();

            // En SQL Server un UNIQUE con columna nullable permite un solo NULL por combinación de
            // (user_id, rol) -> es el comportamiento esperado para roles de alcance global.
            $table->unique(['user_id', 'rol', 'departamento_id'], 'uq_hhee_rol_user_rol_depto');
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index(['rol', 'departamento_id', 'activo'], 'ix_hhee_rol_busqueda');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists($this->tabla);
    }
}
