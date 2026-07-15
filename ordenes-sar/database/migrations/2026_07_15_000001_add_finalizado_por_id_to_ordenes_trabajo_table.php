<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFinalizadoPorIdToOrdenesTrabajoTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ordenes_trabajo', function (Blueprint $table) {
            // Usuario que finalizó la orden
            $table->unsignedBigInteger('finalizado_por_id')->nullable();
            $table->foreign('finalizado_por_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ordenes_trabajo', function (Blueprint $table) {
            $table->dropForeign(['finalizado_por_id']);
            $table->dropColumn('finalizado_por_id');
        });
    }
}
