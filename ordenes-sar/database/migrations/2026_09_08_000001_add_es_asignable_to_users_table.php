<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega 'es_asignable' a users: marca INDIVIDUAL (no por rol) de qué
 * usuarios pueden recibir OTs asignadas (usuario_mantenimiento_id) y
 * finalizarlas, además de los group_leader de siempre. Caso real: Marcelo
 * Ferreyra pasa a Mantenimiento como gerente para asignar OTs (como
 * Lezcano), pero también debe poder recibir OTs asignadas y finalizarlas él
 * mismo. Decisión explícita del negocio: NO abrir esto a todos los gerentes,
 * default 0 (nadie es asignable por el simple hecho de tener determinado
 * rol/departamento) -- se marca a mano usuario por usuario.
 *
 * Ver App\Http\Controllers\OrdenTrabajoController::updateEstado() (el
 * "asignado" de la orden puede finalizarla, sin importar su rol) y
 * App\Http\Controllers\UserController::getUsuariosMantenimiento() (expone el
 * flag para que el front arme el selector de asignables).
 *
 * NO toca el CHECK constraint del enum de users.rol (columna aparte, sin
 * relación).
 */
class AddEsAsignableToUsersTable extends Migration
{
    private string $tabla = 'users';

    private string $columna = 'es_asignable';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->boolean($this->columna)->default(0);
        });

        // Backfill explícito (redundante con el DEFAULT del ALTER, se deja
        // igual que el resto de migraciones "add_*" de este proyecto para
        // dejar registro claro del valor histórico esperado).
        DB::table($this->tabla)->update([$this->columna => 0]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // SQL Server no permite dropear una columna con un default constraint
        // colgando: hay que limpiarlo primero (mismo patrón que
        // 2026_08_05_000001_add_prioridad_to_ordenes_trabajo_table.php).
        $this->dropDefaultConstraints([$this->columna]);

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn($this->columna);
        });
    }

    /**
     * Busca y dropea, con SQL dinámico, los default constraints de las
     * columnas indicadas en sys.default_constraints. No falla si la columna
     * no tiene default.
     */
    private function dropDefaultConstraints(array $columnas): void
    {
        $tabla = $this->tabla;
        $listaColumnas = "'" . implode("','", $columnas) . "'";

        DB::statement("
            DECLARE @sql NVARCHAR(MAX) = '';

            SELECT @sql += 'ALTER TABLE [dbo].[{$tabla}] DROP CONSTRAINT [' + dc.name + '];'
            FROM sys.default_constraints dc
            INNER JOIN sys.columns c
                ON c.default_object_id = dc.object_id
                AND c.object_id = dc.parent_object_id
            WHERE dc.parent_object_id = OBJECT_ID('dbo.{$tabla}')
              AND c.name IN ({$listaColumnas});

            IF @sql <> ''
                EXEC sp_executesql @sql;
        ");
    }
}
