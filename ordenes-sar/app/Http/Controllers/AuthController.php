<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected $rules = [
        'required' => 'El campo :attribute es requerido',
        'string' => 'El campo :attribute debe ser una cadena',
        'max' => 'El campo :attribute no puede tener más de :max caracteres',
        'min' => 'El campo :attribute debe tener al menos :min caracteres',
    ];

    public function login(Request $request)
    {
        

        // Validar las entradas del formulario
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:1',
        ], $this->rules);


        // Si la validación falla, devolver errores
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()], 422);
        }

        // Buscar el usuario por email
        $user = User::with('departamento')->where('email', $request->email)->first();

        // Verificar si el usuario existe y si la contraseña es correcta (texto plano)
        // Verificar si el usuario existe
        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        // Verificar si la contraseña es correcta (texto plano)
        if ($request->password !== $user->password) {
            return response()->json(['message' => 'Contraseña incorrecta'], 401);
        }

        // Si ambas validaciones son correctas, continua con el flujo
        // return response()->json(['message' => 'Inicio de sesión exitoso'], 200);


        try {
           


            // Crear un token para el usuario autenticado
            // $token = $user->createToken('auth_token')->plainTextToken;
            $token = $user->createToken('Laravel Password Gran Client')->accessToken;
            
            return response()->json(['access_token' => $token, 'user' => $user, 'token_type' => 'Bearer']);
        } catch (\Exception $e) {
            
            // Si hay un error al crear el token, devolver mensaje de error
            return response()->json(['message' => 'Error al crear el token', 'error' => $e->getMessage()], 500);
        }
    }


    public function home()
    {
        return response()->json(['message' => 'Welcome to the home page'], 200);
    }
}
