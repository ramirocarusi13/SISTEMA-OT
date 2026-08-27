<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHheeSolicitudesTable extends Migration
{
    private string $tabla = 'hhee_solicitudes';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->tabla, function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('solicitante_id');
            $table->foreign('solicitante_id', 'fk_hhee_sol_solicitante')
                ->references('id')->on('users');

            // "SECTOR" del formulario FO-008-RRH.
            $table->unsignedBigInteger('departamento_id');
            $table->foreign('departamento_id', 'fk_hhee_sol_departamento')
                ->references('id')->on('departamentos');

            $table->date('fecha_hhee');
            $table->string('turno', 50)->nullable();

            // Comentario general opcional de la solicitud; el motivo puntual va por empleado
            // (ver hhee_solicitud_detalles.motivo).
            $table->string('observaciones', 1000)->nullable();

            // borrador | pendiente_nivel1 | pendiente_final | aprobada | cerrada | rechazada | anulada
            $table->string('estado', 20)->default('borrador');

            // Totales denormalizados (se recalculan al guardar detalles / cargar horas reales).
            $table->decimal('total_horas_teoricas', 7, 2)->default(0);
            $table->decimal('total_horas_reales', 7, 2)->default(0);
            $table->integer('total_empleados')->default(0);

            $table->dateTime('fecha_envio')->nullable();
            $table->dateTime('fecha_aprobacion_nivel1')->nullable();
            $table->dateTime('fecha_aprobacion_final')->nullable();
            $table->dateTime('fecha_cierre')->nullable();
            $table->dateTime('fecha_rechazo')->nullable();

            $table->unsignedBigInteger('rechazado_por_id')->nullable();
            $table->foreign('rechazado_por_id', 'fk_hhee_sol_rechazado_por')
                ->references('id')->on('users');
            $table->string('motivo_rechazo', 1000)->nullable();

            $table->timestamps();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index(['estado', 'fecha_hhee'], 'ix_hhee_sol_estado_fecha');
            $table->index('solicitante_id', 'ix_hhee_sol_solicitante');
            $table->index(['departamento_id', 'estado'], 'ix_hhee_sol_depto_estado');
            $table->index('created_at', 'ix_hhee_sol_created');
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
