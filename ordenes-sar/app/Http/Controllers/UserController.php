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

        // Flags de permisos resueltos en el backend, para que el front no tenga que
        // re-derivar la regla (ni hardcodear nombres/ids de departamento en JS).
        // El backend igual bloquea la escritura por middleware: esto es solo para
        // que la UI no ofrezca acciones que van a terminar en 403.
        $payload = $user->toArray();
        $payload['es_seguridad_higiene'] = Departamentos::esSeguridad($user);
        $payload['puede_escribir'] = !$payload['es_seguridad_higiene'];

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
