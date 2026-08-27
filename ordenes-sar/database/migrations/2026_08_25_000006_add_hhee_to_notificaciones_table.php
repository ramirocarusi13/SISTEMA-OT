<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddHheeToNotificacionesTable extends Migration
{
    private string $tabla = 'notificaciones';

    private string $fkOrdenTrabajoNueva = 'fk_notif_orden_trabajo';
    private string $fkSolicitudHhee = 'fk_notif_solicitud_hhee';

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
     * Camino sqlsrv (producción): ALTER incremental vía SQL dinámico sobre
     * sys.foreign_keys/sys.default_constraints, igual que el resto de
     * migraciones "add_*" de este proyecto (ver add_prioridad_to_ordenes_trabajo_table.php).
     */
    private function upSqlsrv(): void
    {
        // 1) orden_trabajo_id pasa a ser opcional: una notificación ahora puede referirse a una OT
        //    o a una solicitud de HHEE. Hay que dropear la FK existente (nombre autogenerado por
        //    Laravel en la migración original) antes de poder alterar la columna.
        $this->dropForeignKeyOnColumn($this->tabla, 'orden_trabajo_id');

        DB::statement("ALTER TABLE [dbo].[{$this->tabla}] ALTER COLUMN [orden_trabajo_id] BIGINT NULL");

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->foreign('orden_trabajo_id', $this->fkOrdenTrabajoNueva)
                ->references('id')->on('ordenes_trabajo')->onDelete('no action');
        });

        // 2) Discriminador de tipo de notificación ('ot' | 'hhee'), NOT NULL con default para no
        //    romper filas existentes.
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->string('tipo', 20)->default('ot');
        });

        // Backfill explícito (redundante con el DEFAULT del ALTER, se deja igual que en
        // add_prioridad_to_ordenes_trabajo_table.php para dejar registro claro del valor histórico).
        DB::table($this->tabla)->update(['tipo' => 'ot']);

        // 3) Relación opcional con hhee_solicitudes. ON DELETE CASCADE es seguro acá: notificaciones
        //    no tiene ninguna otra ruta de cascada activa (orden_trabajo_id, usuario_creador_id y
        //    usuario_mantenimiento_id son todas NO ACTION), así que no se generan múltiples rutas
        //    de cascada hacia esta tabla.
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->unsignedBigInteger('solicitud_hhee_id')->nullable();
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->foreign('solicitud_hhee_id', $this->fkSolicitudHhee)
                ->references('id')->on('hhee_solicitudes')->onDelete('cascade');

            $table->index('solicitud_hhee_id', 'ix_notif_solicitud_hhee');
        });

        // 4) Filtro de la campana de notificaciones (usuario_creador_id = destinatario + leido).
        //    No existía este índice compuesto.
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index(['usuario_creador_id', 'leido'], 'ix_notif_usuario_leido');
        });
    }

    /**
     * Camino genérico (sqlite, entorno de test — ver phpunit.xml): sqlite no soporta
     * DROP FOREIGN KEY ni ALTER COLUMN NOT NULL->NULL sin recrear la tabla, y este
     * proyecto no tiene doctrine/dbal instalado (requerido por Blueprint::change()).
     * Se reconstruye la tabla completa ya con el esquema FINAL (mismo resultado que
     * upSqlsrv(), sin los pasos incrementales), preservando los datos existentes.
     */
    private function upGenerico(): void
    {
        Schema::create('notificaciones_hhee_tmp', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('orden_trabajo_id')->nullable();
            $table->foreign('orden_trabajo_id', $this->fkOrdenTrabajoNueva)
                ->references('id')->on('ordenes_trabajo')->onDelete('no action');

            $table->foreignId('usuario_creador_id')->constrained('users')->onDelete('no action');
            $table->foreignId('usuario_mantenimiento_id')->constrained('users')->onDelete('no action');

            $table->string('estado_anterior');
            $table->string('estado_nuevo');
            $table->boolean('leido')->default(false);
            $table->text('mensaje')->nullable();

            $table->string('tipo', 20)->default('ot');

            $table->unsignedBigInteger('solicitud_hhee_id')->nullable();
            $table->foreign('solicitud_hhee_id', $this->fkSolicitudHhee)
                ->references('id')->on('hhee_solicitudes')->onDelete('cascade');

            $table->timestamps();

            $table->index('solicitud_hhee_id', 'ix_notif_solicitud_hhee');
            $table->index(['usuario_creador_id', 'leido'], 'ix_notif_usuario_leido');
        });

        DB::statement('
            INSERT INTO notificaciones_hhee_tmp
                (id, orden_trabajo_id, usuario_creador_id, usuario_mantenimiento_id,
                 estado_anterior, estado_nuevo, leido, mensaje, tipo, created_at, updated_at)
            SELECT
                id, orden_trabajo_id, usuario_creador_id, usuario_mantenimiento_id,
                estado_anterior, estado_nuevo, leido, mensaje, \'ot\', created_at, updated_at
            FROM notificaciones
        ');

        Schema::drop($this->tabla);
        Schema::rename('notificaciones_hhee_tmp', $this->tabla);
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

    private function downSqlsrv(): void
    {
        // Revertir en orden inverso al up().

        // 4) Índice de la campana.
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropIndex('ix_notif_usuario_leido');
        });

        // 3) FK + índice + columna de solicitud_hhee_id.
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropForeign($this->fkSolicitudHhee);
            $table->dropIndex('ix_notif_solicitud_hhee');
        });

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn('solicitud_hhee_id');
        });

        // 2) Columna tipo (tiene default constraint, hay que limpiarlo antes de dropear).
        $this->dropDefaultConstraints(['tipo']);

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn('tipo');
        });

        // 1) Volver orden_trabajo_id a NOT NULL con su FK original.
        //    Nota: si a esta altura existen filas con orden_trabajo_id NULL (notificaciones de HHEE
        //    creadas mientras la columna era nullable), este ALTER falla; es el comportamiento
        //    esperado de un rollback estructural, no se borran datos de forma silenciosa.
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropForeign($this->fkOrdenTrabajoNueva);
        });

        DB::statement("ALTER TABLE [dbo].[{$this->tabla}] ALTER COLUMN [orden_trabajo_id] BIGINT NOT NULL");

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->foreign('orden_trabajo_id', 'fk_notif_orden_trabajo_original')
                ->references('id')->on('ordenes_trabajo')->onDelete('no action');
        });
    }

    /**
     * Reconstruye la tabla en su forma original (pre-HHEE): descarta las filas de tipo
     * 'hhee' (no tienen equivalente en el esquema viejo, que exige orden_trabajo_id
     * NOT NULL) y las columnas nuevas.
     */
    private function downGenerico(): void
    {
        Schema::create('notificaciones_original_tmp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_trabajo_id')->constrained('ordenes_trabajo')->onDelete('no action');
            $table->foreignId('usuario_creador_id')->constrained('users')->onDelete('no action');
            $table->foreignId('usuario_mantenimiento_id')->constrained('users')->onDelete('no action');
            $table->string('estado_anterior');
            $table->string('estado_nuevo');
            $table->boolean('leido')->default(false);
            $table->text('mensaje')->nullable();
            $table->timestamps();
        });

        DB::statement("
            INSERT INTO notificaciones_original_tmp
                (id, orden_trabajo_id, usuario_creador_id, usuario_mantenimiento_id,
                 estado_anterior, estado_nuevo, leido, mensaje, created_at, updated_at)
            SELECT
                id, orden_trabajo_id, usuario_creador_id, usuario_mantenimiento_id,
                estado_anterior, estado_nuevo, leido, mensaje, created_at, updated_at
            FROM notificaciones
            WHERE tipo != 'hhee' AND orden_trabajo_id IS NOT NULL
        ");

        Schema::drop($this->tabla);
        Schema::rename('notificaciones_original_tmp', $this->tabla);
    }

    /**
     * Busca con SQL dinámico el nombre real de la FK que apunta a la columna indicada
     * (necesario porque Laravel autogeneró su nombre en la migración original) y la dropea.
     * No falla si la columna no tiene ninguna FK.
     */
    private function dropForeignKeyOnColumn(string $tabla, string $columna): void
    {
        DB::statement("
            DECLARE @fkName NVARCHAR(128);
            DECLARE @sql NVARCHAR(MAX);

            SELECT @fkName = fk.name
            FROM sys.foreign_keys fk
            INNER JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
            INNER JOIN sys.columns c
                ON c.object_id = fkc.parent_object_id
                AND c.column_id = fkc.parent_column_id
            WHERE fk.parent_object_id = OBJECT_ID('dbo.{$tabla}')
              AND c.name = '{$columna}';

            IF @fkName IS NOT NULL
            BEGIN
                SET @sql = 'ALTER TABLE [dbo].[{$tabla}] DROP CONSTRAINT [' + @fkName + '];';
                EXEC sp_executesql @sql;
            END
        ");
    }

    /**
     * Busca y dropea, con SQL dinámico, los default constraints de las columnas indicadas
     * en sys.default_constraints (mismo patrón que add_prioridad_to_ordenes_trabajo_table.php).
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
