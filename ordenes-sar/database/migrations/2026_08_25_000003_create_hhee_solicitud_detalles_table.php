<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHheeSolicitudDetallesTable extends Migration
{
    private string $tabla = 'hhee_solicitud_detalles';

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
            $table->foreign('solicitud_id', 'fk_hhee_det_solicitud')
                ->references('id')->on('hhee_solicitudes')->onDelete('cascade');

            // El formulario papel no lo tiene, se agrega para cruzar con RRHH.
            $table->string('legajo', 20)->nullable();

            // Apellido y nombre (texto libre, tal cual se completa en el papel).
            $table->string('nombre', 150);

            // Vínculo opcional con el usuario del sistema (sin cascade: no se quiere borrar
            // detalles históricos de HHEE si se elimina un user).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id', 'fk_hhee_det_user')
                ->references('id')->on('users');

            // El formulario lleva el motivo POR EMPLEADO, no a nivel cabecera.
            $table->string('motivo', 500);

            $table->boolean('necesita_transporte')->default(0);
            // Solo relevante si necesita_transporte = true.
            $table->string('localidad', 150)->nullable();

            $table->time('hora_desde', 0);
            $table->time('hora_hasta', 0);
            $table->boolean('cruza_medianoche')->default(0);

            // Horas teóricas (previstas), calculadas/validadas en backend.
            $table->decimal('hs_teoricas_50', 5, 2)->default(0);
            $table->decimal('hs_teoricas_100', 5, 2)->default(0);
            $table->decimal('hs_teoricas_50n', 5, 2)->default(0);
            $table->decimal('hs_teoricas_100n', 5, 2)->default(0);

            // Horas reales, se cargan una vez aprobada la solicitud.
            $table->decimal('hs_reales_50', 5, 2)->default(0);
            $table->decimal('hs_reales_100', 5, 2)->default(0);
            $table->decimal('hs_reales_50n', 5, 2)->default(0);
            $table->decimal('hs_reales_100n', 5, 2)->default(0);

            // Se carga junto con las horas reales.
            $table->date('fecha_realizacion')->nullable();

            // Orden de la fila dentro de la solicitud (para respetar el orden de carga del formulario).
            $table->smallInteger('orden')->default(0);

            $table->timestamps();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index('solicitud_id', 'ix_hhee_det_solicitud');
            $table->index('legajo', 'ix_hhee_det_legajo');
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
