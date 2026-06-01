<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificacionesTable extends Migration
{
    public function up()
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_trabajo_id')->constrained('ordenes_trabajo')->onDelete('no action');
            $table->foreignId('usuario_creador_id')->constrained('users')->onDelete('no action');
            $table->foreignId('usuario_mantenimiento_id')->constrained('users')->onDelete('no action');
            $table->string('estado_anterior');
            $table->string('estado_nuevo');
            $table->boolean('leido')->default(false);
            $table->text('mensaje')->nullable(); // Campo agregado
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('notificaciones');
    }
}
