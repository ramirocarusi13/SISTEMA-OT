<?php

namespace App\Http\Controllers;

use App\Models\Descripcion;
use App\Support\ArchivoOrden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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
            $archivoUrl = ArchivoOrden::url($descripcion->archivo);

            return [
                'id' => $descripcion->id,
                'titulo' => $descripcion->titulo,
                'descripcion' => $descripcion->descripcion,
                'archivo' => $archivoUrl,
                'archivo_url' => $archivoUrl,
                'archivo_nombre' => $descripcion->archivo,
                'mime_type' => $descripcion->mime_type,
            ];
        });

        return response()->json($descripcionesFormatted);
    }

    public function showArchivo(string $archivo)
    {
        $archivo = basename(rawurldecode($archivo));

        if ($archivo === '' || $archivo === '.' || $archivo === '..') {
            abort(404);
        }

        $directories = [
            public_path('storage/archivos'),
            storage_path('app/public/archivos'),
        ];

        $candidates = array_unique([
            $archivo,
            rawurlencode($archivo),
            str_replace(' ', '%20', $archivo),
            str_replace('%20', ' ', $archivo),
            str_replace('+', ' ', $archivo),
        ]);

        foreach ($directories as $directory) {
            foreach ($candidates as $candidate) {
                $path = $directory . DIRECTORY_SEPARATOR . $candidate;

                if (File::exists($path)) {
                    return response()->file($path, [
                        'Cache-Control' => 'private, max-age=3600',
                    ]);
                }
            }
        }

        $normalizedRequested = $this->normalizeArchivoName($archivo);

        foreach ($directories as $directory) {
            if (!File::isDirectory($directory)) {
                continue;
            }

            foreach (File::files($directory) as $file) {
                if ($this->normalizeArchivoName($file->getFilename()) === $normalizedRequested) {
                    return response()->file($file->getPathname(), [
                    'Cache-Control' => 'private, max-age=3600',
                ]);
                }
            }
        }

        Log::warning("Archivo no encontrado: {$archivo}");

        abort(404);
    }

    private function normalizeArchivoName(string $name): string
    {
        return mb_strtolower(rawurldecode(str_replace('+', ' ', $name)));
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
            'archivo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:5120',
            // Máx: 5MB
        ]);

        $archivoNombre = null;
        $mimeType = null;

        if ($request->hasFile('archivo')) {
            $mimeType = $request->file('archivo')->getMimeType();

            $archivoNombre = ArchivoOrden::store($request->file('archivo'));
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

        $archivoUrl = ArchivoOrden::url($descripcion->archivo);

        return response()->json([
            'id' => $descripcion->id,
            'titulo' => $descripcion->titulo,
            'descripcion' => $descripcion->descripcion,
            'archivo' => $archivoUrl,
            'archivo_url' => $archivoUrl,
            'archivo_nombre' => $descripcion->archivo,
            'mime_type' => $descripcion->mime_type,
        ], 201);
    }
}
