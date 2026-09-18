<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOiInvolucradosTable extends Migration
{
    private string $tabla = 'oi_involucrados';

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
            $table->foreign('issue_id', 'fk_oi_inv_issue')
                ->references('id')->on('oi_issues')->onDelete('cascade');

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'fk_oi_inv_user')
                ->references('id')->on('users');

            // creador | manual | departamento
            $table->string('origen', 20);

            // Solo si origen = 'departamento': de qué depto se expandió (snapshot histórico,
            // no una relación viva).
            $table->unsignedBigInteger('departamento_id')->nullable();
            $table->foreign('departamento_id', 'fk_oi_inv_depto')
                ->references('id')->on('departamentos');

            // Quién lo agregó (si origen = 'creador', el propio creador).
            $table->unsignedBigInteger('agregado_por_id');
            $table->foreign('agregado_por_id', 'fk_oi_inv_agregado_por')
                ->references('id')->on('users');

            // Append-only: sin updated_at.
            $table->dateTime('created_at')->nullable();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            // Invariante: 1 fila por (issue, user). Ya cubre los filtros por issue_id (columna líder).
            $table->unique(['issue_id', 'user_id'], 'uq_oi_inv_issue_user');

            // Resuelve "en qué issues participo".
            $table->index('user_id', 'ix_oi_inv_user');
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
