<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planificación de HHEE por rango de fechas ("desde/hasta", pedido de RRHH
 * oct-2026): cuando se cargan horas extras de antemano para varios días, se
 * genera UNA solicitud por día (cada una con su circuito de firmas y su carga
 * de horas reales, igual que siempre) y todas comparten el mismo
 * planificacion_id (uuid). Null = solicitud suelta de un solo día.
 *
 * Ver App\Support\HheeFlujo::planificar() / accionEnPlanificacion().
 */
class AddPlanificacionToHheeSolicitudesTable extends Migration
{
    private string $tabla = 'hhee_solicitudes';

    public function up()
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->string('planificacion_id', 36)->nullable();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index('planificacion_id', 'ix_hhee_sol_planificacion');
        });
    }

    public function down()
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropIndex('ix_hhee_sol_planificacion');
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn('planificacion_id');
        });
    }
}
