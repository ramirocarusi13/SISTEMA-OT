<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddItemToOiActualizacionesTable extends Migration
{
    private string $tabla = 'oi_actualizaciones';

    private string $fkItem = 'fk_oi_act_item';

    private string $indiceItem = 'ix_oi_act_item';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (DB::getDriverName() === 'sqlsrv') {
            $this->upSqlsrv();
        } else {
            $this->upGenerico();
        }
    }

    /**
     * Camino sqlsrv (producción): agrega `item_id` con FK **NO ACTION** (nunca cascade). `oi_issues`
     * ya cascadea hacia `oi_actualizaciones` (vía `issue_id`) y hacia `oi_items` (vía `issue_id`); si
     * esta FK fuera CASCADE se abriría una segunda ruta oi_issues -> oi_items -> oi_actualizaciones
     * hacia la misma tabla destino y SQL Server la rechaza con el error 1785 (ver §1.0 / §10.1).
     *
     * NOTA (revisión #8): por ser NO ACTION, borrar físicamente una fila de `oi_issues` que tenga
     * actualizaciones ligadas a items (`oi_actualizaciones.item_id`) puede fallar con el error 547 de
     * SQL Server (la cascada `oi_issues` -> `oi_actualizaciones` choca con esta FK `fk_oi_act_item`,
     * que exige que `oi_items` siga existiendo). No es un problema real: ni los issues ni los items se
     * borran nunca (§5.9, §10.1: se pasan a `cerrado`/`descartado`), así que ese DELETE físico nunca
     * debería ejecutarse en producción.
     */
    private function upSqlsrv(): void
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->foreign('item_id', $this->fkItem)
                ->references('id')->on('oi_items');

            $table->index(['item_id', 'id'], $this->indiceItem);
        });
    }

    /**
     * Camino genérico (sqlite de tests, mysql del .env.example). Alcanza con agregar la columna
     * nullable y su índice: no hace falta declarar la FK acá porque en sqlite agregar una FK a una
     * tabla ya existente compila a un no-op silencioso (ver el comentario de
     * `2026_09_18_000004_add_open_issue_to_notificaciones_table.php`), así que no aporta nada.
     */
    private function upGenerico(): void
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable();
            $table->index(['item_id', 'id'], $this->indiceItem);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (DB::getDriverName() === 'sqlsrv') {
            $this->downSqlsrv();
        } else {
            $this->downGenerico();
        }
    }

    /**
     * La columna no tiene default constraint, así que no hace falta el barrido de
     * sys.default_constraints (igual que el down de la migración 2026_09_18_000004).
     */
    private function downSqlsrv(): void
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropForeign($this->fkItem);
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropIndex($this->indiceItem);
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn('item_id');
        });
    }

    /**
     * En el camino genérico nunca se creó una FK real (ver upGenerico()), así que alcanza con
     * dropear el índice y la columna: no hace falta reconstruir la tabla. `dropColumn` funciona acá
     * de forma nativa (sin doctrine/dbal) porque este proyecto no lo tiene instalado, lo que hace que
     * Laravel emita un `ALTER TABLE ... DROP COLUMN` directo, soportado por sqlite >= 3.35.
     *
     * El down() no se usa nunca en este proyecto (rollback prohibido). Se escribe igual por
     * completitud y simetría con el precedente.
     */
    private function downGenerico(): void
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropIndex($this->indiceItem);
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn('item_id');
        });
    }
}
