<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHheeAprobacionesTable extends Migration
{
    private string $tabla = 'hhee_aprobaciones';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->tabla, function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('solicitud_id');
            $table->foreign('solicitud_id', 'fk_hhee_aprob_solicitud')
                ->references('id')->on('hhee_solicitudes')->onDelete('cascade');

            // 1 = nivel área (jefe/gerente_area), 2 = nivel final (gerencia_general/rrhh/presidencia).
            $table->tinyInteger('nivel');

            // pendiente | aprobada | rechazada
            $table->string('estado', 15)->default('pendiente');

            // Sin cascade: solicitud_id ya cascadea desde hhee_solicitudes, y SQL Server no permite
            // múltiples rutas de cascada hacia la misma tabla (users) en esta tabla.
            $table->unsignedBigInteger('aprobador_id')->nullable();
            $table->foreign('aprobador_id', 'fk_hhee_aprob_aprobador')
                ->references('id')->on('users');

            $table->string('rol_aprobador', 30)->nullable();
            $table->boolean('es_contingencia')->default(0);
            $table->string('comentario', 1000)->nullable();
            $table->dateTime('firmado_at')->nullable();

            $table->timestamps();

            $table->unique(['solicitud_id', 'nivel'], 'uq_hhee_aprob_nivel');
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index(['estado', 'nivel'], 'ix_hhee_aprob_estado_nivel');
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
