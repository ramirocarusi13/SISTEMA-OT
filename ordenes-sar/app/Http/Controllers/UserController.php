<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * Mostrar todos los usuarios.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Obtener todos los usuarios
        $users = User::all();

        return response()->json($users);
    }

    /**
     * Obtener el usuario autenticado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAuthenticatedUser()
    {
        // Obtener el usuario autenticado
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Usuario no autenticado'], 401);
        }

        // 'es_seguridad_higiene' ya viaja solo (accessor + $appends en
        // App\Models\User, ver getEsSeguridadHigieneAttribute()): no hace
        // falta calcularlo a mano acá. El permiso de escritura tampoco es un
        // interruptor global (antes 'puede_escribir'): ahora viaja por OT en
        // el campo 'solo_lectura' de cada orden (ver
        // OrdenTrabajoController::index/show y App\Support\AlcanceOrdenes::
        // puedeEditar), porque un usuario de SyH puede escribir en las OTs de
        // su propio departamento y solo es de lectura en las ajenas que ve
        // por estar marcadas de seguridad.
        return response()->json($user->toArray());
    }

    /**
     * Mostrar un usuario específico por ID.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        // Buscar el usuario por ID
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        return response()->json($user);
    }

    /**
     * Obtener usuarios del departamento de mantenimiento.
     *
     * 'es_asignable' viaja solo en cada usuario (columna real del modelo,
     * sin necesidad de armarla a mano): el front la usa para el selector de
     * asignables, hoy limitado a group_leader por convención propia del
     * front, más los usuarios marcados individualmente con este flag (ver
     * migración 2026_09_08_000001_add_es_asignable_to_users_table.php).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUsuariosMantenimiento()
    {
        // Buscar usuarios del departamento de mantenimiento (departamento_id 2)
        $usuariosMantenimiento = User::where('departamento_id', 2)->get();


        return  response()->json($usuariosMantenimiento);
    }
}
