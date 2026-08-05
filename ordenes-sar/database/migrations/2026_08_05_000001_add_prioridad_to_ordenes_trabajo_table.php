<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPrioridadToOrdenesTrabajoTable extends Migration
{
    /**
     * Nombre de la tabla, para no repetir el literal en todo el archivo.
     */
    private string $tabla = 'ordenes_trabajo';

    /**
     * Columnas que se agregan con un DEFAULT (necesitan limpieza de su
     * default constraint en el down() antes de poder dropearse en SQL Server).
     */
    private array $columnasConDefault = ['categoria', 'prioridad', 'prioridad_orden', 'es_seguridad'];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            // Categoría elegida por el creador -> determina la prioridad automática (ver App\Support\PrioridadOT)
            $table->string('categoria', 30)->default('averia')->after('titulo');

            // Prioridad calculada (o overrideada) y su orden numérico para poder ordenar sin CASE en cada query
            $table->string('prioridad', 10)->default('media')->after('categoria');
            $table->tinyInteger('prioridad_orden')->default(3)->after('prioridad');

            // Marca de seguridad: fuerza prioridad crítica y no puede bajarse mientras esté en true
            $table->boolean('es_seguridad')->default(0)->after('prioridad_orden');

            // Override manual de prioridad: quién lo hizo y por qué (obligatorio si baja la prioridad, se valida en el controller)
            $table->unsignedBigInteger('prioridad_definida_por_id')->nullable()->after('es_seguridad');
            $table->text('prioridad_motivo')->nullable()->after('prioridad_definida_por_id');

            // Primera transición fuera de 'creada' (para métricas de reportes, ver App\Support\ReporteQueries)
            $table->timestamp('fecha_primera_respuesta')->nullable()->after('fecha_asignacion');
        });

        // FK del override en un paso aparte, con nombre explícito para poder dropearla puntualmente en el down()
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->foreign('prioridad_definida_por_id', 'fk_ot_prioridad_definida_por')
                ->references('id')->on('users');
        });

        // Backfill explícito de las filas existentes. En SQL Server, al agregar una columna NOT NULL con
        // DEFAULT, el motor ya completa las filas existentes con ese default como parte del propio ALTER
        // TABLE; este UPDATE es redundante pero se deja explícito (tal como pide la spec) para no depender
        // de ese comportamiento implícito y dejar registro claro de cuál es el valor "histórico" esperado.
        DB::table($this->tabla)->update([
            'categoria' => 'averia',
            'prioridad' => 'media',
            'prioridad_orden' => 3,
            'es_seguridad' => 0,
        ]);

        // Índices (todos con nombre explícito, requeridos por los reportes agregados)
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->index(['estado', 'prioridad_orden'], 'ix_ot_estado_prioridad');
            $table->index('created_at', 'ix_ot_created_at');
            $table->index('usuario_mantenimiento_id', 'ix_ot_usuario_mantenimiento');
            $table->index('fecha_finalizacion', 'ix_ot_fecha_finalizacion');

            // usuario_id tiene FK desde la migración original pero SQL Server NO crea un índice
            // automáticamente para las FKs (a diferencia de MySQL); se usa para joinear con el
            // departamento del creador en los reportes, así que hace falta indexarlo explícitamente.
            $table->index('usuario_id', 'ix_ot_usuario_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // 1) Dropear la FK del override antes que su columna
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropForeign('fk_ot_prioridad_definida_por');
        });

        // 2) Dropear los índices explícitos antes de las columnas que referencian
        //    (ix_ot_estado_prioridad incluye prioridad_orden, que se va a dropear)
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropIndex('ix_ot_estado_prioridad');
            $table->dropIndex('ix_ot_created_at');
            $table->dropIndex('ix_ot_usuario_mantenimiento');
            $table->dropIndex('ix_ot_fecha_finalizacion');
            $table->dropIndex('ix_ot_usuario_id');
        });

        // 3) SQL Server no permite dropear una columna con un default constraint colgando.
        //    Laravel 10 ya intenta limpiar los default constraints al hacer dropColumn(), pero lo forzamos
        //    igual con SQL crudo por las dudas (por ejemplo si el constraint fue renombrado a mano o
        //    restaurado desde un backup con otro nombre que no siga el patrón que espera el framework).
        $this->dropDefaultConstraints($this->columnasConDefault);

        // 4) Recién ahora se pueden dropear las columnas
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn([
                'categoria',
                'prioridad',
                'prioridad_orden',
                'es_seguridad',
                'prioridad_definida_por_id',
                'prioridad_motivo',
                'fecha_primera_respuesta',
            ]);
        });
    }

    /**
     * Busca y dropea, con SQL dinámico, los default constraints de las columnas indicadas
     * en sys.default_constraints. No falla si alguna columna no tiene default (simplemente no
     * genera sentencia para ella).
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
