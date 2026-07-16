<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMensajeLecturasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mensaje_lecturas', function (Blueprint $table) {
            $table->id();

            // Relación con ordenes_trabajo (chat que se está leyendo)
            $table->unsignedBigInteger('orden_trabajo_id')->index();
            $table->foreign('orden_trabajo_id')->references('id')->on('ordenes_trabajo')->onDelete('cascade');

            // Relación con users (usuario que leyó el chat)
            // Nota: ordenes_trabajo.usuario_id / usuario_mantenimiento_id / finalizado_por_id
            // no tienen onDelete('cascade') hacia users (ver esas migraciones), por lo que
            // no hay riesgo de "multiple cascade paths" al poner cascade acá (mismo patrón
            // que usa create_mensajes_table.php).
            $table->unsignedBigInteger('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Hasta cuándo leyó el usuario el chat de esta orden (estilo "visto" de WhatsApp)
            $table->dateTime('last_read_at')->nullable();

            $table->timestamps();

            // Nombre corto y explícito: SQL Server puede tener problemas con los nombres
            // largos que autogenera Laravel para constraints compuestas.
            $table->unique(['orden_trabajo_id', 'user_id'], 'UQ_mensaje_lecturas_ot_user');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mensaje_lecturas');
    }
}
