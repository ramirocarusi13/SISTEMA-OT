<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOiIssuesTable extends Migration
{
    private string $tabla = 'oi_issues';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->tabla, function (Blueprint $table) {
            $table->id();

            $table->string('titulo', 200);
            $table->string('descripcion', 4000)->nullable();

            $table->unsignedBigInteger('departamento_destino_id');
            $table->foreign('departamento_destino_id', 'fk_oi_issue_depto_destino')
                ->references('id')->on('departamentos');

            $table->unsignedBigInteger('creador_id');
            $table->foreign('creador_id', 'fk_oi_issue_creador')
                ->references('id')->on('users');

            // abierto | en_progreso | cerrado (validado en PHP, App\Support\OpenIssueEstados).
            $table->string('estado', 20)->default('abierto');

            // baja | media | alta (validado en PHP, App\Support\OpenIssueEstados).
            $table->string('prioridad', 20)->default('media');

            $table->dateTime('fecha_cierre')->nullable();

            $table->unsignedBigInteger('cerrado_por_id')->nullable();
            $table->foreign('cerrado_por_id', 'fk_oi_issue_cerrado_por')
                ->references('id')->on('users');

            $table->dateTime('fecha_reapertura')->nullable();

            $table->timestamps();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index(['estado', 'created_at'], 'ix_oi_issues_estado_created');
            $table->index(['departamento_destino_id', 'estado'], 'ix_oi_issues_depto_estado');
            $table->index('creador_id', 'ix_oi_issues_creador');
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
