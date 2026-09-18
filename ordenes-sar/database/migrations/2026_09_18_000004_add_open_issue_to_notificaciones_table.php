<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddOpenIssueToNotificacionesTable extends Migration
{
    private string $tabla = 'notificaciones';

    private string $fkOpenIssue = 'fk_notif_open_issue';

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
     * Camino sqlsrv (producción): solo agrega la columna `open_issue_id`. NO toca `tipo`
     * (ya existe, es nvarchar(20), entra 'open_issue' que son 10 chars y no hay CHECK que
     * restrinja valores), NO toca `orden_trabajo_id` (ya es nullable desde la migración HHEE),
     * NO toca los índices existentes.
     */
    private function upSqlsrv(): void
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->unsignedBigInteger('open_issue_id')->nullable();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            // ON DELETE CASCADE es seguro acá: oi_issues no tiene padres en cascada, así que
            // no se genera una segunda ruta de cascada hacia notificaciones (ver §1.0).
            $table->foreign('open_issue_id', $this->fkOpenIssue)
                ->references('id')->on('oi_issues')->onDelete('cascade');

            $table->index('open_issue_id', 'ix_notif_open_issue');
        });
    }

    /**
     * Camino genérico (sqlite de tests, mysql del .env.example). A diferencia de la migración
     * HHEE, acá NO hace falta reconstruir la tabla: solo se agrega una columna, y
     * `ALTER TABLE ADD COLUMN` sí lo soporta sqlite.
     */
    private function upGenerico(): void
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->unsignedBigInteger('open_issue_id')->nullable();
            $table->index('open_issue_id', 'ix_notif_open_issue');
        });

        // En sqlite, Illuminate\Database\Schema\Grammars\SQLiteGrammar::compileForeign() devuelve
        // null ("Handled on table creation"): agregar una FK a una tabla YA existente es un no-op
        // silencioso, no un error (Blueprint::toSql descarta los comandos que compilan a null).
        // En mysql sí se crea. Los tests no dependen de que la FK exista: la integridad la
        // garantizan App\Support\OpenIssueFlujo / OpenIssueNotificador.
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->foreign('open_issue_id', $this->fkOpenIssue)
                ->references('id')->on('oi_issues')->onDelete('cascade');
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
     * sys.default_constraints (a diferencia del down de la migración HHEE).
     */
    private function downSqlsrv(): void
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropForeign($this->fkOpenIssue);
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropIndex('ix_notif_open_issue');
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn('open_issue_id');
        });
    }

    /**
     * Sqlite en Laravel 10 no puede dropColumn sin doctrine/dbal (no instalado), así que se
     * reconstruye la tabla con el esquema post-HHEE / pre-OpenIssues, descartando las filas
     * tipo = 'open_issue' (misma técnica y mismos comentarios que el downGenerico() de la
     * migración HHEE).
     *
     * El down() no se usa nunca en este proyecto (rollback prohibido). Se escribe igual por
     * completitud y simetría con el precedente.
     */
    private function downGenerico(): void
    {
        Schema::create('notificaciones_preoi_tmp', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('orden_trabajo_id')->nullable();
            $table->foreign('orden_trabajo_id', 'fk_notif_orden_trabajo')
                ->references('id')->on('ordenes_trabajo')->onDelete('no action');

            $table->foreignId('usuario_creador_id')->constrained('users')->onDelete('no action');
            $table->foreignId('usuario_mantenimiento_id')->constrained('users')->onDelete('no action');

            $table->string('estado_anterior');
            $table->string('estado_nuevo');
            $table->boolean('leido')->default(false);
            $table->text('mensaje')->nullable();

            $table->string('tipo', 20)->default('ot');

            $table->unsignedBigInteger('solicitud_hhee_id')->nullable();
            $table->foreign('solicitud_hhee_id', 'fk_notif_solicitud_hhee')
                ->references('id')->on('hhee_solicitudes')->onDelete('cascade');

            $table->timestamps();

            $table->index('solicitud_hhee_id', 'ix_notif_solicitud_hhee');
            $table->index(['usuario_creador_id', 'leido'], 'ix_notif_usuario_leido');
        });

        DB::statement("
            INSERT INTO notificaciones_preoi_tmp
                (id, orden_trabajo_id, usuario_creador_id, usuario_mantenimiento_id,
                 estado_anterior, estado_nuevo, leido, mensaje, tipo, solicitud_hhee_id,
                 created_at, updated_at)
            SELECT
                id, orden_trabajo_id, usuario_creador_id, usuario_mantenimiento_id,
                estado_anterior, estado_nuevo, leido, mensaje, tipo, solicitud_hhee_id,
                created_at, updated_at
            FROM notificaciones
            WHERE tipo != 'open_issue'
        ");

        Schema::drop($this->tabla);
        Schema::rename('notificaciones_preoi_tmp', $this->tabla);
    }
}
