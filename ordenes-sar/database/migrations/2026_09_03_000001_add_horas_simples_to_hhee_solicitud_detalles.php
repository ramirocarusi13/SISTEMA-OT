<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simplificación de la carga de HHEE: por empleado ya no se pide desglose por
 * tipo de hora (50/100/50n/100n) ni el flag cruza_medianoche -- el backend
 * calcula solo TOTAL de horas a partir de hora_desde/hora_hasta (ver
 * App\Support\HheeFlujo::calcularHoras()/calcularHorasTeoricas()). Agrega
 * `horas_teoricas` y `horas_reales` (un solo número cada una) a
 * hhee_solicitud_detalles.
 *
 * NO se borran las columnas viejas (hs_teoricas_50/100/50n/100n,
 * hs_reales_50/100/50n/100n, legajo): quedan en el schema sin uso por la
 * aplicación (legajo y el desglose por tipo ya no se piden en el form;
 * cruza_medianoche se sigue escribiendo, pero ahora como dato informativo
 * derivado del cálculo automático, no como input del usuario). Se documenta
 * acá en vez de dropearlas para no perder datos históricos de solicitudes
 * cargadas antes de este cambio y evitar una migración destructiva.
 *
 * Simple addColumn (con default 0, NOT NULL), sin ALTER de columnas
 * existentes ni SQL dinámico: corre igual en sqlsrv y en sqlite (tests).
 */
class AddHorasSimplesToHheeSolicitudDetalles extends Migration
{
    private string $tabla = 'hhee_solicitud_detalles';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->decimal('horas_teoricas', 5, 2)->default(0);
            $table->decimal('horas_reales', 5, 2)->default(0);
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
            $table->dropColumn(['horas_teoricas', 'horas_reales']);
        });
    }
}
