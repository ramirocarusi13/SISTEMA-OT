<?php

namespace App\Http\Controllers;

use App\Models\Descripcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DescripcionController extends Controller
{
    /**
     * Obtener todas las descripciones de una orden de trabajo específica.
     *
     * @param int $idOrden
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDescripcionesByOrdenId($idOrden)
    {
        // Obtener todas las descripciones que pertenecen a la orden de trabajo especificada
        $descripciones = Descripcion::where('orden_id', $idOrden)->get();

        // Formatear la respuesta
        $descripcionesFormatted = $descripciones->map(function ($descripcion) {
            return [
                'id' => $descripcion->id,
                'titulo' => $descripcion->titulo,
                'descripcion' => $descripcion->descripcion,
                'archivo' => $descripcion->archivo ? asset('storage/archivos/' . $descripcion->archivo) : null,
                'mime_type' => $descripcion->mime_type,
            ];
        });

        return response()->json($descripcionesFormatted);
    }

    /**
     * Guardar una nueva descripción.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validar los datos entrantes
        $request->validate([
            'titulo' => 'required|string|max:255',
            'descripcion' => 'required|string',
            'orden_id' => 'required|exists:ordenes_trabajo,id',
            'archivo' => 'nullable|file|mimes:jpg,png,jpeg,pdf,doc,docx,xlsx|max:5120', 
            // Máx: 5MB
        ]);

        $archivoNombre = null;
        $mimeType = null;

        if ($request->hasFile('archivo')) {
            // Obtener la extensión y el tipo MIME del archivo
            $extension = $request->file('archivo')->getClientOriginalExtension();
            $mimeType = $request->file('archivo')->getMimeType();

            // Crear un nombre único basado en la fecha y hora actuales
            $fechaHora = now()->format('Ymd_His');
            $archivoNombre = "archivo_{$fechaHora}.{$extension}";

            // Guardar el archivo en el almacenamiento
            $request->file('archivo')->storeAs('public/archivos', $archivoNombre);
        }

        // Crear una nueva descripción con el archivo y tipo MIME
        $descripcion = Descripcion::create([
            'titulo' => $request->titulo,
            'descripcion' => $request->descripcion,
            'orden_id' => $request->orden_id,
            'archivo' => $archivoNombre,
            'mime_type' => $mimeType,
        ]);

        Log::info("Archivo subido: {$archivoNombre} con MIME: {$mimeType}");

        return response()->json($descripcion, 201);
    }
}
