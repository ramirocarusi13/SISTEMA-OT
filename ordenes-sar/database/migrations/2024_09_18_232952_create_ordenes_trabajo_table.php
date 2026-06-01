<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdenesTrabajoTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ordenes_trabajo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id');
            $table->foreign('usuario_id')->references('id')->on('users');
            $table->text('titulo')->nullable();
            $table->enum('estado', [ 'creada' ,'aprobada', 'asignada' , 'en_proceso', 'finalizada']);
            $table->text('comentarios')->nullable();
            $table->timestamp('fecha_aprobacion')->nullable();
            $table->timestamp('fecha_asignacion')->nullable();
            $table->timestamp('fecha_estimacion')->nullable();
            $table->timestamp('fecha_finalizacion')->nullable();
            $table->unsignedBigInteger('horas_ot')->nullable();
            $table->text('mensaje_finalizacion')->nullable();
            $table->string('foto_finalizada')->nullable();

            // Relación con usuarios de mantenimiento
            $table->unsignedBigInteger('usuario_mantenimiento_id')->nullable();
            $table->foreign('usuario_mantenimiento_id')->references('id')->on('users');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ordenes_trabajo');
    }
}
