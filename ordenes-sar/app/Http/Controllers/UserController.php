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

        return response()->json($user);
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
