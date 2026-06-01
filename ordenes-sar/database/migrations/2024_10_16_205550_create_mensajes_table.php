<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMensajesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mensajes', function (Blueprint $table) {
            $table->id();
            
            // Relación con ordenes_trabajo
            $table->unsignedBigInteger('orden_trabajo_id')->index();
            $table->foreign('orden_trabajo_id')->references('id')->on('ordenes_trabajo')->onDelete('cascade');
            
            // Relación con users (usuario que envía el mensaje)
            $table->unsignedBigInteger('usuario_id')->index();
            $table->foreign('usuario_id')->references('id')->on('users')->onDelete('cascade');
            
            // Campo para el mensaje
            $table->text('mensaje');

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
        Schema::dropIfExists('mensajes');
    }
}
