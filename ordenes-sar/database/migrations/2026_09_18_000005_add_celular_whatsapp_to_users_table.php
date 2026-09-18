<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a users el celular (para WhatsApp) y el flag individual
 * 'whatsapp_activo' que habilita el envío de notificaciones por ese canal a
 * ese usuario puntual. Igual que 'es_asignable' (ver
 * 2026_09_08_000001_add_es_asignable_to_users_table.php), es una marca
 * INDIVIDUAL: cargar el celular NO activa el canal solo, hay que además
 * tildar 'whatsapp_activo' a mano (comando `whatsapp:usuario`, ver
 * App\Console\Commands\WhatsAppUsuarioCommand). Default 0: nadie recibe
 * WhatsApp hasta que se configure explícitamente.
 *
 * Ver App\Support\WhatsApp::chatIdDesdeCelular() (normalización del celular a
 * chatId de OpenWA) y App\Support\WhatsAppNotificador (quién se entera de
 * qué por este canal).
 */
class AddCelularWhatsappToUsersTable extends Migration
{
    private string $tabla = 'users';

    /**
     * Columnas con DEFAULT: necesitan limpieza de su default constraint en
     * el down() antes de poder dropearse en SQL Server (mismo patrón que
     * 2026_09_08_000001_add_es_asignable_to_users_table.php). 'celular' es
     * nullable SIN default, no entra en esta lista.
     */
    private array $columnasConDefault = ['whatsapp_activo'];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table($this->tabla, function (Blueprint $table) {
            $table->string('celular', 30)->nullable();
            $table->boolean('whatsapp_activo')->default(0);
        });

        // Backfill explícito (redundante con el DEFAULT del ALTER, se deja
        // igual que el resto de migraciones "add_*" de este proyecto para
        // dejar registro claro del valor histórico esperado).
        DB::table($this->tabla)->update(['whatsapp_activo' => 0]);
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
        // 2026_09_08_000001_add_es_asignable_to_users_table.php).
        $this->dropDefaultConstraints($this->columnasConDefault);

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn(['celular', 'whatsapp_activo']);
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
