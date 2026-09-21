<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOiItemsTable extends Migration
{
    private string $tabla = 'oi_items';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->tabla, function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('issue_id');
            $table->foreign('issue_id', 'fk_oi_item_issue')
                ->references('id')->on('oi_issues')->onDelete('cascade');

            $table->string('titulo', 300);
            $table->string('detalle', 2000)->nullable();

            // pendiente | en_progreso | hecho | descartado (validado en PHP, App\Support\OpenIssueEstados).
            $table->string('estado', 20)->default('pendiente');

            $table->unsignedBigInteger('responsable_id')->nullable();
            $table->foreign('responsable_id', 'fk_oi_item_responsable')
                ->references('id')->on('users');

            $table->unsignedBigInteger('creado_por_id');
            $table->foreign('creado_por_id', 'fk_oi_item_creado_por')
                ->references('id')->on('users');

            $table->unsignedBigInteger('resuelto_por_id')->nullable();
            $table->foreign('resuelto_por_id', 'fk_oi_item_resuelto_por')
                ->references('id')->on('users');

            $table->dateTime('fecha_resuelto')->nullable();

            // Orden de carga dentro del issue (1..n). Se recalcula al agregar items.
            $table->smallInteger('orden')->default(0);

            $table->timestamps();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            // Listado de items de un issue en su orden de carga.
            $table->index(['issue_id', 'orden'], 'ix_oi_item_issue_orden');

            // "Mis items" por responsable, filtrados por estado.
            $table->index(['responsable_id', 'estado'], 'ix_oi_item_responsable_estado');
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
