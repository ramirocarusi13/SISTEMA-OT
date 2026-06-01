<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDescripcionesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('descripciones', function (Blueprint $table) {
            $table->id(); // ID autoincremental
            $table->string('titulo')->nullable(); // Campo para el título
            $table->text('descripcion'); // Campo para la descripción
            $table->unsignedBigInteger('orden_id'); // Relación con la tabla ordenes_trabajo
            $table->foreign('orden_id')->references('id')->on('ordenes_trabajo')->onDelete('cascade'); // Clave foránea
            $table->string('archivo')->nullable();
            $table->string('mime_type')->nullable();
            
            $table->timestamps(); // Campos created_at y updated_at
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('descripciones'); // Eliminar tabla si existe
    }
}
