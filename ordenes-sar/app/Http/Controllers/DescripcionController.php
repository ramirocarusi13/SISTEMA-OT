<?php

namespace App\Http\Controllers;

use App\Models\Descripcion;
use App\Models\OrdenTrabajo;
use App\Support\AlcanceOrdenes;
use App\Support\ArchivoOrden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DescripcionController extends Controller
{
    /**
     * Obtener todas las descripciones de una orden de trabajo específica.
     *
     * Antes no validaba ningún alcance (gap preexistente detectado por code
     * review): cualquier usuario autenticado podía leer las descripciones de
     * cualquier OT por id, sin importar su departamento. Ahora usa el mismo
     * criterio que OrdenTrabajoController::show()/getFotoFinalizada() y
     * MensajeController::index() (App\Support\AlcanceOrdenes::puedeVer()):
     * Mantenimiento/admin ven todas, SyH ve las de seguridad + las propias,
     * el resto solo las de su propio departamento.
     *
     * @param int $idOrden
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDescripcionesByOrdenId($idOrden)
    {
        $orden = OrdenTrabajo::with('creador')->find($idOrden);

        if (!$orden) {
            return response()->json(['error' => 'Orden de trabajo no encontrada'], 404);
        }

        if (!AlcanceOrdenes::puedeVer(auth()->user(), $orden)) {
            return response()->json(['error' => 'No tiene permisos para ver esta orden'], 403);
        }

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

    /**
     * Sirve un archivo adjunto por NOMBRE de archivo (no por id/orden_id):
     * no recibe ninguna referencia a la OT dueña, así que no se le puede
     * aplicar el mismo guard de AlcanceOrdenes::puedeVer() que se agregó en
     * getDescripcionesByOrdenId() sin antes resolver a qué OT pertenece cada
     * archivo (join contra `descripciones` por nombre de archivo, con sus
     * propias inconsistencias: nombres duplicados/legacy, archivos sin fila
     * en `descripciones`, etc.). Gap preexistente conocido, documentado acá a
     * propósito: cualquier usuario autenticado que conozca/adivine el nombre
     * exacto de un archivo puede descargarlo. Fuera de alcance de este fix
     * (ver code review); si se quiere cerrar, hay que rediseñar el endpoint
     * para que reciba el id de la Descripcion (no el nombre de archivo) y
     * recién ahí aplicar el alcance por la OT relacionada.
     */
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
        return strtolower(rawurldecode(str_replace('+', ' ', $name)));
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
