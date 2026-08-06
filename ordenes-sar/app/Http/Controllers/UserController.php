<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Departamentos;
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

        // Flag de contexto resuelto en el backend, para que el front no tenga que
        // hardcodear nombres/ids de departamento en JS. El permiso de escritura ya
        // NO es un interruptor global (antes 'puede_escribir'): ahora viaja por OT
        // en el campo 'solo_lectura' de cada orden (ver OrdenTrabajoController::index/show
        // y App\Support\AlcanceOrdenes::puedeEditar), porque un usuario de SyH puede
        // escribir en las OTs de su propio departamento y solo es de lectura en las
        // ajenas que ve por estar marcadas de seguridad.
        $payload = $user->toArray();
        $payload['es_seguridad_higiene'] = Departamentos::esSeguridad($user);

        return response()->json($payload);
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
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUsuariosMantenimiento()
    {
        // Buscar usuarios del departamento de mantenimiento (departamento_id 2)
        $usuariosMantenimiento = User::where('departamento_id', 2)->get();
        
        
        return  response()->json($usuariosMantenimiento);
    }
}
