<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega 'sector' a hhee_solicitudes (Corte | Costura | Mantenimiento | PC,
 * ver config('hhee.sectores')): reemplaza al selector libre de
 * departamento_id en el formulario -- departamento_id se sigue guardando
 * (ahora SIEMPRE el del solicitante, ver App\Support\HheeFlujo::crear()) para
 * no romper el ruteo de aprobadores de nivel 1, que sigue siendo por
 * departamento.
 *
 * Simple addColumn + index, sin ALTER de columnas existentes ni SQL dinámico:
 * a diferencia de 2026_08_25_000006 (que sí necesitó una rama no-sqlsrv),
 * esta migración corre igual en sqlsrv y en sqlite (tests), no hace falta
 * separar caminos.
 */
class AddSectorToHheeSolicitudesTable extends Migration
{
    private string $tabla = 'hhee_solicitudes';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            // Nullable: las solicitudes existentes (si las hubiera) no tienen
            // sector cargado.
            $table->string('sector', 50)->nullable();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index(['sector', 'estado'], 'ix_hhee_sol_sector_estado');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropIndex('ix_hhee_sol_sector_estado');
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn('sector');
        });
    }
}
