<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Departamentos ADICIONALES de un usuario, además de users.departamento_id
 * (que sigue siendo su departamento "base" y no cambia). Caso real: Agustín
 * Otero es gerente de Mantenimiento y además gerente de Ingeniería; desde el
 * front hace switch entre los dos y, mientras tiene Ingeniería activo, el
 * backend lo trata como si su departamento fuera Ingeniería (ve y aprueba
 * las OTs de Ingeniería). Ver App\Http\Middleware\DepartamentoActivo.
 *
 * Se carga a mano usuario por usuario (como users.es_asignable). Esta
 * migración ya deja cargado el caso de Agustín si el usuario y el
 * departamento existen en la base.
 */
class CreateUserDepartamentosAdicionalesTable extends Migration
{
    private string $tabla = 'user_departamentos_adicionales';

    public function up()
    {
        Schema::create($this->tabla, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('departamento_id');
            $table->timestamps();

            $table->unique(['user_id', 'departamento_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('departamento_id')->references('id')->on('departamentos');
        });

        $agustin = DB::table('users')->where('email', 'agustin.otero@sewtechar.com')->first();
        $ingenieria = DB::table('departamentos')->where('nombre', 'like', 'Ingenier%')->first();

        if ($agustin && $ingenieria && (int) $agustin->departamento_id !== (int) $ingenieria->id) {
            DB::table($this->tabla)->insert([
                'user_id' => $agustin->id,
                'departamento_id' => $ingenieria->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        Schema::dropIfExists($this->tabla);
    }
}
