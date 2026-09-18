<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOiActualizacionesTable extends Migration
{
    private string $tabla = 'oi_actualizaciones';

    /**
     * Tabla append-only: la app nunca hace UPDATE ni DELETE sobre ella.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->tabla, function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('issue_id');
            $table->foreign('issue_id', 'fk_oi_act_issue')
                ->references('id')->on('oi_issues')->onDelete('cascade');

            // Autor de la actualización.
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'fk_oi_act_user')
                ->references('id')->on('users');

            // Vocabulario cerrado (8 valores, App\Support\OpenIssueEstados):
            // apertura | comentario | cambio_estado | cierre | reapertura |
            // involucrado_agregado | involucrado_quitado | edicion
            $table->string('tipo', 30);

            $table->string('texto', 4000)->nullable();
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20)->nullable();

            // Nombre devuelto por App\Support\ArchivoOrden::store().
            $table->string('archivo', 255)->nullable();
            $table->string('mime_type', 150)->nullable();

            // Append-only: sin updated_at.
            $table->dateTime('created_at')->nullable();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            // Timeline ordenado.
            $table->index(['issue_id', 'id'], 'ix_oi_act_issue_id');

            // MAX(created_at) del listado (withMax).
            $table->index(['issue_id', 'created_at'], 'ix_oi_act_issue_created');
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
