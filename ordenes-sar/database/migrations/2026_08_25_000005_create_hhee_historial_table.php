<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHheeHistorialTable extends Migration
{
    private string $tabla = 'hhee_historial';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->tabla, function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('solicitud_id');
            $table->foreign('solicitud_id', 'fk_hhee_hist_solicitud')
                ->references('id')->on('hhee_solicitudes')->onDelete('cascade');

            // Sin cascade: no queremos que borrar un user arrastre el historial (append-only / auditoría).
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'fk_hhee_hist_user')
                ->references('id')->on('users');

            // creada | editada | enviada | aprobada_nivel1 | aprobada_final | horas_reales_cargadas |
            // cerrada | rechazada | anulada
            $table->string('accion', 30);

            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20)->nullable();
            $table->boolean('es_contingencia')->default(0);
            $table->string('comentario', 1000)->nullable();

            // Tabla append-only: no lleva updated_at, solo el momento en que se registró el evento.
            $table->dateTime('created_at')->nullable();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index(['solicitud_id', 'id'], 'ix_hhee_hist_solicitud');
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
