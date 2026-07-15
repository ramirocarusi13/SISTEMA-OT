<?php

namespace App\Http\Controllers;

use App\Http\Roles;
use App\Mail\OrdenCreadaMail;
use App\Mail\OrdenEstadoCambiadoMail;
use App\Models\Descripcion;
use App\Models\Notificacion;
use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Support\ArchivoOrden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class OrdenTrabajoController extends Controller
{
    /**
     * Mostrar todas las órdenes de trabajo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Obtener el usuario autenticado
        $userLogueado = auth()->user();

        // Obtener el rol y departamento del usuario
        $rol = $userLogueado->rol;
        $departamentoId = $userLogueado->departamento_id;

        // Obtener filtros desde la solicitud
        $filtroDepartamento = $request->input('departamento_id');
        $filtroUsuarioMantenimiento = $request->input('usuario_mantenimiento_id');
        $filtroFechaInicio = $request->input('fecha_inicio');
        $filtroFechaFin = $request->input('fecha_fin');

        // Construir consulta base dependiendo del usuario
        if ($departamentoId == 2) {  // Mantenimiento
            $query = OrdenTrabajo::with(['creador', 'usuarioMantenimiento', 'descripcion', 'creador.departamento', 'finalizadoPor']);
        } elseif ($rol == 'gerente') {
            $query = OrdenTrabajo::with(['creador', 'usuarioMantenimiento', 'descripcion', 'creador.departamento', 'finalizadoPor'])
                ->whereHas('creador', function ($q) use ($departamentoId) {
                    $q->where('departamento_id', $departamentoId);
                });
        } else {
            $query = OrdenTrabajo::with(['creador', 'usuarioMantenimiento', 'descripcion', 'creador.departamento', 'finalizadoPor'])
                ->whereHas('creador', function ($q) use ($departamentoId) {
                    $q->where('departamento_id', $departamentoId);
                });
        }

        // Aplicar filtro por departamento si existe
        if (!empty($filtroDepartamento)) {
            $query->whereHas('creador.departamento', function ($q) use ($filtroDepartamento) {
                $q->where('id', $filtroDepartamento);
            });
        }

        if (!empty($filtroUsuarioMantenimiento)) {
            $query->where('usuario_mantenimiento_id', $filtroUsuarioMantenimiento);
        }

        // Aplicar filtro por rango de fechas si existen
        if (!empty($filtroFechaInicio) && !empty($filtroFechaFin)) {
            $query->whereBetween('created_at', [$filtroFechaInicio, $filtroFechaFin]);
        }

        // Obtener los resultados
        $ordenesTrabajo = $query->get();

        // Formatear la respuesta
        $ordenesFormatted = $ordenesTrabajo->map(function ($orden) {
            return [
                'id' => $orden->id,
                'titulo' => $orden->titulo,
                'usuario_creador' => $orden->creador ? $orden->creador->name : null,
                'usuario_creador_id' => $orden->creador ? $orden->creador->id : null, // Agregar el ID del creador
                'departamento_creador' => $orden->creador->departamento ? $orden->creador->departamento->nombre : null,
                'turno' => $orden->creador ? $orden->creador->turno : null,
                'departamento_id' => $orden->creador->departamento ? intval($orden->creador->departamento->id) : null,
                'descripcion' => $orden->descripcion ? $orden->descripcion->descripcion : null,
                'estado' => $orden->estado,
                'usuario_mantenimiento' => $orden->usuarioMantenimiento ? $orden->usuarioMantenimiento->name : null,
                'usuario_mantenimiento_id' => $orden->usuarioMantenimiento ? $orden->usuarioMantenimiento->id : null,
                'comentarios' => $orden->comentarios,
                'fecha_aprobacion' => $orden->fecha_aprobacion,
                'fecha_estimacion' => $orden->fecha_estimacion,
                'fecha_finalizacion' => $orden->fecha_finalizacion,
                'foto_finalizada' => $orden->foto_finalizada,
                'finalizado_por' => $orden->finalizadoPor ? $orden->finalizadoPor->name : null,
                'finalizado_por_id' => $orden->finalizado_por_id,
                'created_at' => $orden->created_at,
                'updated_at' => $orden->updated_at,
            ];
        });

        // Retornar las órdenes de trabajo en formato JSON
        return response()->json($ordenesFormatted);
    }
    public function filtrarOrdenes(Request $request)
    {
        $query = OrdenTrabajo::query();

        if ($request->filled('departamento_id')) {
            $query->where('departamento_id', $request->departamento_id);
        }

        if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
            $query->whereBetween('created_at', [$request->fecha_inicio, $request->fecha_fin]);
        }

        $ordenes = $query->with('departamento', 'usuarioCreador')->get();

        return response()->json($ordenes);
    }
    public function getFotoFinalizada($id)
    {
        $ordenTrabajo = OrdenTrabajo::find($id);

        if ($ordenTrabajo && $ordenTrabajo->foto_finalizada) {
            $fotoNombre = $ordenTrabajo->getRawOriginal('foto_finalizada');

            return response()->json([
                'foto_finalizada' => $ordenTrabajo->foto_finalizada,
                'foto_finalizada_nombre' => $fotoNombre,
            ]);
        }

        // Registro de error si no se encuentra la foto
        Log::warning("No se encontró foto finalizada para la orden con ID: $id");
        return response()->json(['message' => 'Foto no encontrada'], 404);
    }







    public function show($id)
    {
        try {
            // Buscar la orden de trabajo con sus relaciones
            $orden = OrdenTrabajo::with(['creador', 'usuarioMantenimiento', 'descripciones', 'finalizadoPor'])
                ->findOrFail($id); // Lanza una excepción si no se encuentra la orden

            // Formatear la respuesta
            $ordenFormatted = [
                'id' => $orden->id,
                'titulo' => $orden->titulo,
                'usuario_creador' => $orden->creador ? $orden->creador->name : null,
                'departamento_creador' => $orden->creador->departamento ? $orden->creador->departamento->nombre : null,
                'descripcion' => $orden->descripcion ? $orden->descripcion->descripcion : null,
                'estado' => $orden->estado,
                'usuario_mantenimiento' => $orden->usuarioMantenimiento ? $orden->usuarioMantenimiento->name : null,
                'comentarios' => $orden->comentarios,
                'fecha_aprobacion' => $orden->fecha_aprobacion,
                'fecha_finalizacion' => $orden->fecha_finalizacion,
                'created_at' => $orden->created_at,
                'updated_at' => $orden->updated_at,
            ];

            return response()->json($orden);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Orden de trabajo no encontrada: ' . $e->getMessage());
            return response()->json(['message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            Log::error('Error al obtener la orden de trabajo: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener la orden de trabajo'], 500);
        }
    }





    public function updateEstado(Request $request, $id)
    {
        // Validar los datos recibidos
        $validatedData = $request->validate([
            'estado' => 'required|string|in:pendiente,en_proceso,finalizada,asignada',
            'detalle' => 'nullable|string',
            'usuario_mantenimiento_id' => 'required_if:estado,asignada|exists:users,id',
            'fecha_estimacion' => 'nullable|date',
            'fecha_asignacion' => 'nullable|date',
            'horas_ot' => 'nullable|integer',
            'mensaje_finalizacion' => 'required_if:estado,finalizada|string|nullable',
            'foto_finalizada' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg|max:5120', // Validar que sea una imagen
        ]);

        try {
            $orden = OrdenTrabajo::findOrFail($id);

            // Un group_leader solo puede modificar órdenes que tiene asignadas
            $userLogueado = auth()->user();
            if ($userLogueado->rol === Roles::GROUP_LEADER && (int) $orden->usuario_mantenimiento_id !== (int) $userLogueado->id) {
                return response()->json(['error' => 'No tiene permisos para modificar esta orden'], 403);
            }

            // Finalizar solo puede: el GL dueño (ya validado arriba), el gerente de mantenimiento,
            // el gerente del departamento del creador, o el analista creador de la orden
            if ($request->estado === 'finalizada' && $userLogueado->rol !== Roles::GROUP_LEADER) {
                $esGerenteMantenimiento = $userLogueado->rol === Roles::GERENTE && (int) $userLogueado->departamento_id === 2;
                $esGerenteDelCreador = $userLogueado->rol === Roles::GERENTE
                    && $orden->creador
                    && (int) $userLogueado->departamento_id === (int) $orden->creador->departamento_id;
                $esAnalistaCreador = $userLogueado->rol === 'analista' && (int) $orden->usuario_id === (int) $userLogueado->id;

                if (!$esGerenteMantenimiento && !$esGerenteDelCreador && !$esAnalistaCreador) {
                    return response()->json(['error' => 'No tiene permisos para finalizar esta orden'], 403);
                }
            }

            $estadoAnterior = $orden->estado;

            // Asignar usuario si el estado es 'asignada'
            if ($request->estado === 'asignada') {
                $orden->usuario_mantenimiento_id = $request->usuario_mantenimiento_id;

                // Asignar fecha_estimacion si fue proporcionada
                if ($request->filled('fecha_estimacion')) {
                    // $fecha = \DateTime::createFromFormat('Y-m-d', $request->fecha_estimacion);

                    $orden->fecha_estimacion = $request->fecha_estimacion; //$fecha->format('Y-m-d H:i:s.v');

                }
            }

            // Actualizar estado
            $orden->estado = $request->estado;

            // Establecer fecha de aprobación si es necesario
            if (is_null($orden->fecha_aprobacion) && $request->estado === 'asignada') {
                $orden->fecha_aprobacion = Carbon::now('America/Argentina/Buenos_Aires');
            }

            // Actualizar fecha de finalización si el estado es 'finalizada'
            if ($request->estado === 'finalizada') {
                $orden->fecha_finalizacion = Carbon::now('America/Argentina/Buenos_Aires');
                $orden->finalizado_por_id = auth()->id();

                // Procesar y guardar la foto si fue subida
                if ($request->hasFile('foto_finalizada')) {
                    $foto = $request->file('foto_finalizada');

                    $fotoNombre = ArchivoOrden::store($foto, 'foto_finalizada');

                    // Actualizar el campo en la tabla ordenes_trabajo
                    $orden->foto_finalizada = $fotoNombre;
                }
            }

            // Actualizar otros campos
            $orden->horas_ot = $request->horas_ot;
            $orden->mensaje_finalizacion = $request->mensaje_finalizacion;

            $orden->save();

            // Enviar notificación (si aplica)
            $this->sendNotification($orden->usuario_id, $orden->id, $estadoAnterior, $request->estado, $request->detalle);

            return response()->json($orden);
        } catch (\Exception $e) {
            Log::error('Error actualizando el estado de la orden de trabajo: ' . $e->getMessage());
            return response()->json(['error' => 'Error actualizando el estado de la orden de trabajo'], 500);
        }
    }
    public function finalizarOrdenTrabajo(Request $request, $id)
{
    $user = auth()->user();
    $orden = OrdenTrabajo::with('creador')->findOrFail($id);

    // Validar permisos
    $esCreador = $user->id === $orden->usuario_id;
    $esGerente = $user->rol === 'gerente';
    $esMismoDepartamento = $orden->creador && $orden->creador->departamento_id === $user->departamento_id;

    if ($esGerente && $esMismoDepartamento) {
        // OK: gerente del mismo dpto.
    } elseif ($esCreador && $orden->estado === 'creada') {
        // OK: creador y estado "creada"
    } else {
        return response()->json(['error' => 'No tiene permisos para finalizar esta orden'], 403);
    }

    // Validar input
    $request->validate([
        'mensaje_finalizacion' => 'required|string|max:255',
    ]);

    // Crear notificación
    Notificacion::create([
        'orden_trabajo_id' => $orden->id,
        'usuario_creador_id' => $orden->usuario_id,
        'usuario_mantenimiento_id' => $user->id,
        'estado_anterior' => $orden->estado,
        'estado_nuevo' => 'finalizada',
        'mensaje' => $request->mensaje_finalizacion,
        'leido' => false,
    ]);

    // Finalizar OT
    $orden->estado = 'finalizada';
    $orden->mensaje_finalizacion = $request->mensaje_finalizacion;
    $orden->fecha_finalizacion = Carbon::now();
    $orden->finalizado_por_id = $user->id;
    $orden->save();

    return response()->json(['message' => 'Orden finalizada con éxito.']);
}




    public function finalizarOrdenTrabajo2(Request $request, $id)
    {
        // Buscar la orden
        $orden = OrdenTrabajo::findOrFail($id);

        // Un group_leader solo puede finalizar órdenes que tiene asignadas
        $userLogueado = auth()->user();
        if ($userLogueado->rol === Roles::GROUP_LEADER && (int) $orden->usuario_mantenimiento_id !== (int) $userLogueado->id) {
            return response()->json(['error' => 'No tiene permisos para modificar esta orden'], 403);
        }

        // Validar que la orden esté en estado "aprobada" antes de finalizar


        // Validar la solicitud
        $request->validate([
            'mensaje_finalizacion' => 'required|string|max:255',
        ]);

        // Guardar estado anterior en la tabla notificaciones
        Notificacion::create([
            'orden_trabajo_id' => $orden->id,
            'usuario_creador_id' => $orden->usuario_id, // Quien creó la OT
            'usuario_mantenimiento_id' => Auth::id(), // Usuario que finaliza la OT
            'estado_anterior' => $orden->estado, // Estado antes del cambio
            'estado_nuevo' => 'finalizada', // Estado nuevo
            'mensaje' => $request->mensaje_finalizacion,
            'leido' => false,
        ]);

        // Actualizar el estado de la orden a "finalizada"
        $orden->estado = 'finalizada';
        $orden->mensaje_finalizacion = $request->mensaje_finalizacion;
        $orden->fecha_finalizacion = Carbon::now();
        $orden->finalizado_por_id = Auth::id();
        $orden->save();

        return response()->json(['message' => 'Orden finalizada con éxito.']);
    }


    public function aprobarOrden($id)
    {
        try {
            $userLogueado = auth()->user();

            // Verifica que el usuario sea gerente y que sea del mismo departamento del creador de la orden
            $orden = OrdenTrabajo::with('creador')->findOrFail($id);
            if ($userLogueado->rol != 'gerente' || intval($userLogueado->departamento_id) != intval($orden->creador->departamento_id)) {
                return response()->json(['error' => true, 'message' => 'No tiene permisos para aprobar esta orden'], 403);
            }

            // Verifica que la orden esté en estado "creada"
            if ($orden->estado !== 'creada') {
                return response()->json(['error' => 'Solo se pueden aprobar órdenes en estado creada'], 400);
            }

            // Cambia el estado a "aprobada" y guarda la fecha de aprobación
            $orden->estado = 'aprobada';
            $orden->fecha_aprobacion = now();
            $orden->save();

            // Envía una notificación
            $this->sendNotification($orden->usuario_id, $orden->id, 'creada', 'aprobada', 'La orden ha sido aprobada.');

            return response()->json(['message' => 'Orden aprobada con éxito', 'orden' => $orden], 200);
        } catch (\Exception $e) {
            Log::error('Error al aprobar la orden de trabajo: ' . $e->getMessage());
            return response()->json(['error' => 'Error al aprobar la orden'], 500);
        }
    }


    protected function sendNotification($creadorId, $ordenId, $estadoAnterior, $estadoNuevo, $mensaje = null)
    {
        $ordenTrabajo = OrdenTrabajo::find($ordenId);
        $creador = $ordenTrabajo->creador;
        $usuario = auth()->user(); // Obtienes al usuario logueado que está enviando la notificación

        // Enviar el correo
        Mail::to($ordenTrabajo->creador->email)->send(new OrdenEstadoCambiadoMail($ordenTrabajo, $estadoAnterior, $estadoNuevo, $usuario));

        // Crear la notificación
        Notificacion::create([
            'orden_trabajo_id' => $ordenId,
            'usuario_creador_id' => $creador->id,
            'usuario_mantenimiento_id' => $usuario->id, // Utilizas al usuario logueado aquí
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'leido' => false,
            'mensaje' => $mensaje,
        ]);
    }
    protected function sendNotificationCreateOT($ordenTrabajo)
    {
        $userLogueado = auth()->user(); // Usuario que creó la orden

        if (!$userLogueado) {
            Log::error('No se pudo obtener el usuario autenticado para enviar la notificación.');
            return;
        }

        $departamento = $userLogueado->departamento; // Obtener el departamento del usuario

        if (!$departamento) {
            Log::warning('El usuario no tiene un departamento asociado: ' . $userLogueado->id);
            return;
        }

        // Obtener todos los gerentes asociados al departamento
        $gerentes = $departamento->gerentes; // Suponiendo que la relación se llama "gerentes"

        if ($gerentes->isEmpty()) {
            Log::warning('No se encontraron gerentes asociados al departamento del usuario: ' . $departamento->id);
            return;
        }

        foreach ($gerentes as $gerente) {
            if ($gerente->email) {
                try {
                    // Enviar correo a cada gerente
                    Mail::to($gerente->email)->send(new OrdenCreadaMail($ordenTrabajo, $userLogueado));
                } catch (\Exception $e) {
                    Log::error('Error al enviar el correo al gerente: ' . $gerente->email . ' para la orden: ' . $ordenTrabajo->id . '. Detalles: ' . $e->getMessage());
                }
            } else {
                Log::warning('El gerente no tiene un correo electrónico válido: ' . $gerente->id);
            }
        }
    }




    public function store(Request $request)
    {
        $request->validate([
            'titulo' => 'required|string|max:255',
            'descripcion' => 'required|string',
            'comentarios' => 'nullable|string',
            'usuario_mantenimiento_id' => 'nullable|exists:users,id',
            'estado' => 'required|string',
            'archivos.*' => 'required|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:99120',
        ]);

        // |file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:28120

        try {
            $userLogueado = auth()->user();

            // Crear la orden de trabajo
            $orden = OrdenTrabajo::create([
                'titulo' => $request->titulo,
                'descripcion' => $request->descripcion,
                'comentarios' => $request->comentarios,
                'usuario_id' => $userLogueado->id,
                'usuario_mantenimiento_id' => $request->usuario_mantenimiento_id,
                'estado' => $userLogueado->rol == 'gerente' ? 'aprobada' : $request->estado,
                'fecha_estimacion' => $request->fecha_estimacion,
                'fecha_finalizacion' => $request->fecha_finalizacion,
                'fecha_aprobacion' => $userLogueado->rol == 'gerente' ? now() : null,
            ]);

            // Verificar y guardar archivos subidos
            if ($request->hasFile('archivos')) {
                foreach ($request->file('archivos') as $archivo) {
                    $mimeType = $archivo->getMimeType();
                    $archivoNombre = ArchivoOrden::store($archivo);

                    // Crear una entrada en la tabla `descripciones` para cada archivo
                    Descripcion::create([
                        'orden_id' => $orden->id,
                        'descripcion' => $request->descripcion,
                        'archivo' => $archivoNombre,
                        'mime_type' => $mimeType,
                    ]);
                }
            } else {
                // Crear una entrada en `descripciones` si no se subieron archivos
                Descripcion::create([
                    'orden_id' => $orden->id,
                    'descripcion' => $request->descripcion,
                    'archivo' => null,
                ]);
            }

            // Enviar notificación al gerente del área
            $this->sendNotificationCreateOT($orden);

            return response()->json($orden, 201);
        } catch (\Exception $e) {
            Log::error('Error creando la orden de trabajo: ' . $e->getMessage());
            return response()->json(['error' => 'Error creando la orden de trabajo'], 500);
        }
    }
    public function destroy($id)
    {
        try {
            // Buscar la orden de trabajo por ID
            $orden = OrdenTrabajo::findOrFail($id);

            // Verificar si el usuario tiene permisos para eliminar
            $userLogueado = auth()->user();

            if ($userLogueado->rol !== 'admin' && intval($userLogueado->id) !== intval($orden->usuario_id)) {
                return response()->json(['error' => 'No tiene permisos para eliminar esta orden'], 403);
            }

            // Eliminar la orden
            $orden->delete();

            // Retornar respuesta de éxito
            return response()->json(['message' => 'Orden de trabajo eliminada con éxito'], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Manejar el caso donde no se encuentra la orden
            return response()->json(['error' => 'Orden de trabajo no encontrada'], 404);
        } catch (\Exception $e) {
            // Manejar cualquier otro error
            Log::error('Error al eliminar la orden de trabajo: ' . $e->getMessage());
            return response()->json(['error' => 'Error al eliminar la orden de trabajo'], 500);
        }
    }
    public function agregarArchivos(Request $request, $ordenId)
    {
        $request->validate([
            'archivos.*' => 'required|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:99120', // Validar archivos
        ]);

        try {
            // Buscar la orden de trabajo
            $orden = OrdenTrabajo::find($ordenId);

            if (!$orden) {
                return response()->json(['error' => 'Orden de trabajo no encontrada'], 404);
            }

            // Un group_leader solo puede agregar archivos a órdenes que tiene asignadas
            $userLogueado = auth()->user();
            if ($userLogueado->rol === Roles::GROUP_LEADER && (int) $orden->usuario_mantenimiento_id !== (int) $userLogueado->id) {
                return response()->json(['error' => 'No tiene permisos para modificar esta orden'], 403);
            }

            // Verificar y guardar archivos subidos
            if ($request->hasFile('archivos')) {
                foreach ($request->file('archivos') as $archivo) {
                    $mimeType = $archivo->getMimeType();
                    $archivoNombre = ArchivoOrden::store($archivo);

                    // Crear una entrada en la tabla `descripciones` para cada archivo
                    Descripcion::create([
                        'orden_id' => $orden->id,
                        'descripcion' => $request->descripcion ?? 'Archivo subido sin descripción', // Valor predeterminado
                        'archivo' => $archivoNombre,
                        'mime_type' => $mimeType,
                    ]);
                }

                return response()->json([
                    'message' => 'Archivos subidos correctamente',
                    'orden_id' => $orden->id,
                ], 200);
            }

            return response()->json(['error' => 'No se subieron archivos'], 400);
        } catch (\Exception $e) {
            Log::error('Error al agregar archivos a la orden de trabajo: ' . $e->getMessage());
            return response()->json(['error' => 'Error al agregar archivos'], 500);
        }
    }
}
