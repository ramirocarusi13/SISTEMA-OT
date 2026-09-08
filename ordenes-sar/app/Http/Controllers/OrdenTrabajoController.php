<?php

namespace App\Http\Controllers;

use App\Http\Roles;
use App\Mail\OrdenCreadaMail;
use App\Mail\OrdenEstadoCambiadoMail;
use App\Models\Descripcion;
use App\Models\Notificacion;
use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Support\AlcanceOrdenes;
use App\Support\ArchivoOrden;
use App\Support\PrioridadOT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

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
        // Filtros nuevos de prioridad (§5 de OrdenTrabajoController::index en la spec)
        $filtroPrioridad = array_filter((array) $request->input('prioridad', []));
        $filtroCategoria = array_filter((array) $request->input('categoria', []));
        // Filtro por estado (array, multi-select): evita que el front tenga que traer
        // TODAS las OTs y filtrar en el cliente. Valores válidos: creada, aprobada,
        // asignada, en_proceso, finalizada (mismos que la columna 'estado').
        $filtroEstado = array_filter((array) $request->input('estado', []));
        $filtroSoloVencidas = $request->boolean('solo_vencidas');

        // Closure reutilizable para contar los mensajes no leídos por el usuario logueado en cada orden
        // y el total de mensajes de la orden (el reporte lo usa para distinguir "tiene conversación
        // pero ya la leí" de "no tiene ningún mensaje", que el contador de no leídos no diferencia).
        $userLogueadoId = $userLogueado->id;
        $withMensajesNoLeidos = function ($query) use ($userLogueadoId) {
            $query->withCount('mensajes as mensajes_total');

            $query->withCount(['mensajes as mensajes_no_leidos' => function ($q) use ($userLogueadoId) {
                $q->where('usuario_id', '!=', $userLogueadoId)
                    ->where('created_at', '>', function ($sub) use ($userLogueadoId) {
                        $sub->selectRaw("COALESCE(MAX(last_read_at), '1900-01-01')")
                            ->from('mensaje_lecturas')
                            ->whereColumn('mensaje_lecturas.orden_trabajo_id', 'mensajes.orden_trabajo_id')
                            ->where('mensaje_lecturas.user_id', $userLogueadoId);
                    });
            }]);
        };

        // Construir consulta base dependiendo del usuario. El alcance (qué OTs
        // puede ver cada quien) vive centralizado en App\Support\AlcanceOrdenes
        // para no repetir la regla acá, en MensajeController y en ReporteController.
        // Para los roles/departamentos existentes esto arma EXACTAMENTE la misma
        // query que antes (MTTO ve todo; el resto solo lo del propio departamento,
        // antes duplicado en las ramas 'gerente' y 'else'); lo único nuevo es la
        // rama de Seguridad e Higiene (SyH), que antes no existía.
        $query = OrdenTrabajo::with(['creador', 'usuarioMantenimiento', 'descripcion', 'creador.departamento', 'finalizadoPor']);
        $query = AlcanceOrdenes::aplicar($query, $userLogueado);

        $withMensajesNoLeidos($query);

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

        // Filtros nuevos de prioridad/categoría (multi-select) y "solo vencidas"
        if (!empty($filtroPrioridad)) {
            $query->whereIn('prioridad', $filtroPrioridad);
        }

        if (!empty($filtroCategoria)) {
            $query->whereIn('categoria', $filtroCategoria);
        }

        if (!empty($filtroEstado)) {
            $query->whereIn('estado', $filtroEstado);
        }

        if ($filtroSoloVencidas) {
            // Misma definición de "vencida" que en los reportes (§5): activa, sin asignar
            // todavía y ya pasó el vencimiento de su SLA de primera respuesta.
            $query->where('estado', '!=', 'finalizada')
                ->whereNull('fecha_asignacion')
                ->whereRaw(\App\Support\ReporteQueries::vencimientoSql() . ' < ?', [Carbon::now('America/Argentina/Buenos_Aires')]);
        }

        // Orden por defecto: prioridad_orden ASC (más urgente primero), luego más nueva a más vieja
        $ordenesTrabajo = $query->orderBy('prioridad_orden', 'asc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // Formatear la respuesta
        $ordenesFormatted = $ordenesTrabajo->map(function ($orden) use ($userLogueado) {
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
                'mensajes_no_leidos' => (int) $orden->mensajes_no_leidos,
                'mensajes_total' => (int) $orden->mensajes_total,
                'created_at' => $orden->created_at,
                'updated_at' => $orden->updated_at,
                // Prioridad / categoría / SLA (ver App\Support\PrioridadOT y accessors de OrdenTrabajo)
                'categoria' => $orden->categoria,
                'prioridad' => $orden->prioridad,
                'prioridad_orden' => $orden->prioridad_orden,
                'es_seguridad' => (bool) $orden->es_seguridad,
                'sla_estado' => $orden->sla_estado,
                'sla_vence_at' => $orden->sla_vence_at,
                'cumplio_sla' => $orden->cumplio_sla,
                'fecha_asignacion' => $orden->fecha_asignacion,
                // Alcance de ESCRITURA por OT (App\Support\AlcanceOrdenes::puedeEditar):
                // hoy solo da true para Seguridad e Higiene mirando una OT ajena que ve
                // por estar marcada de seguridad. Para todo el resto siempre es false.
                // 'creador' ya viene eager-loaded en $query, no dispara query por fila.
                'solo_lectura' => !AlcanceOrdenes::puedeEditar($userLogueado, $orden),
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
        $ordenTrabajo = OrdenTrabajo::with('creador')->find($id);

        if (!$ordenTrabajo) {
            Log::warning("No se encontró foto finalizada para la orden con ID: $id");
            return response()->json(['message' => 'Foto no encontrada'], 404);
        }

        // Mismo alcance que show()/index(): antes cualquier usuario autenticado
        // podía leer la foto de cualquier OT por id, sin importar su departamento.
        if (!AlcanceOrdenes::puedeVer(auth()->user(), $ordenTrabajo)) {
            return response()->json(['error' => 'No tiene permisos para ver esta orden'], 403);
        }

        if ($ordenTrabajo->foto_finalizada) {
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

            $userLogueado = auth()->user();

            // Antes acá no se validaba alcance: cualquier usuario autenticado
            // podía leer cualquier OT por id (secuencial), sin importar su
            // departamento. Se aplica el mismo criterio que index()/mensajes.
            if (!AlcanceOrdenes::puedeVer($userLogueado, $orden)) {
                return response()->json(['error' => 'No tiene permisos para ver esta orden'], 403);
            }

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

            // Alcance de ESCRITURA por OT (mismo campo que index(), ver
            // App\Support\AlcanceOrdenes::puedeEditar). Se agrega como atributo
            // dinámico sobre el modelo porque este endpoint devuelve $orden (no
            // $ordenFormatted) en la respuesta.
            $orden->solo_lectura = !AlcanceOrdenes::puedeEditar($userLogueado, $orden);

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
            // el gerente del departamento del creador, el analista creador de la orden, o
            // cualquier usuario (sin importar su rol) que sea EL ASIGNADO de esta orden puntual.
            // Este último caso cubre a usuarios como Marcelo Ferreyra (gerente que pasó a
            // Mantenimiento para asignar OTs, pero que también recibe y finaliza las suyas): no
            // depende de users.es_asignable (ese flag solo gobierna a quién puede elegir el front
            // como asignable al momento de asignar, ver UserController::getUsuariosMantenimiento);
            // acá alcanza con que la orden ya esté asignada a este usuario, igual que la regla de
            // arriba para group_leader.
            if ($request->estado === 'finalizada' && $userLogueado->rol !== Roles::GROUP_LEADER) {
                $esGerenteMantenimiento = $userLogueado->rol === Roles::GERENTE && (int) $userLogueado->departamento_id === 2;
                $esGerenteDelCreador = $userLogueado->rol === Roles::GERENTE
                    && $orden->creador
                    && (int) $userLogueado->departamento_id === (int) $orden->creador->departamento_id;
                $esAnalistaCreador = $userLogueado->rol === 'analista' && (int) $orden->usuario_id === (int) $userLogueado->id;
                $esAsignado = (int) $orden->usuario_mantenimiento_id === (int) $userLogueado->id;

                if (!$esGerenteMantenimiento && !$esGerenteDelCreador && !$esAnalistaCreador && !$esAsignado) {
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

                // FIX de trazabilidad (bug preexistente, ver SPEC §4): la columna fecha_asignacion
                // existía pero nunca se seteaba, dejando sin datos las métricas de tiempo de
                // respuesta. Se guarda solo la PRIMERA asignación (no pisa reasignaciones
                // posteriores, que es lo que necesita el reporte de tiempo de respuesta).
                if (is_null($orden->fecha_asignacion)) {
                    $orden->fecha_asignacion = Carbon::now('America/Argentina/Buenos_Aires');
                }
            }

            // Primera transición fuera de 'creada' (cualquier estado nuevo): marca de
            // "primera respuesta" para las métricas de reportes (§4/§5 de la spec).
            if ($estadoAnterior === 'creada' && $request->estado !== 'creada' && is_null($orden->fecha_primera_respuesta)) {
                $orden->fecha_primera_respuesta = Carbon::now('America/Argentina/Buenos_Aires');
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
            $orden->fecha_aprobacion = Carbon::now('America/Argentina/Buenos_Aires');

            // FIX de trazabilidad (§4 de la spec): también es una transición fuera de 'creada'
            if (is_null($orden->fecha_primera_respuesta)) {
                $orden->fecha_primera_respuesta = Carbon::now('America/Argentina/Buenos_Aires');
            }

            $orden->save();

            // Envía una notificación
            $this->sendNotification($orden->usuario_id, $orden->id, 'creada', 'aprobada', 'La orden ha sido aprobada.');

            return response()->json(['message' => 'Orden aprobada con éxito', 'orden' => $orden], 200);
        } catch (\Exception $e) {
            Log::error('Error al aprobar la orden de trabajo: ' . $e->getMessage());
            return response()->json(['error' => 'Error al aprobar la orden'], 500);
        }
    }

    /**
     * Override manual de la prioridad calculada automáticamente (§1 de la spec).
     * Solo admin, gerente o usuarios de MTTO (departamento_id = 2) pueden overridear;
     * el creador (analista) no puede. Si se BAJA la prioridad (orden numérico mayor,
     * o sea menos urgente que la calculada) hay que justificarlo con prioridad_motivo.
     * Si la orden está marcada es_seguridad, no se puede bajar de 'critica'.
     */
    public function updatePrioridad(Request $request, $id)
    {
        $userLogueado = auth()->user();

        // Solo admin (rol legacy, hoy no insertable pero se deja el chequeo por compatibilidad),
        // gerente o usuarios del departamento de Mantenimiento (id 2) pueden overridear la prioridad
        $puedeOverridear = $userLogueado->rol === 'admin'
            || $userLogueado->rol === Roles::GERENTE
            || (int) $userLogueado->departamento_id === 2;

        if (!$puedeOverridear) {
            return response()->json(['error' => 'No tiene permisos para modificar la prioridad de esta orden'], 403);
        }

        $orden = OrdenTrabajo::with('creador')->findOrFail($id);

        // Además del rol, hay que respetar el alcance por departamento: un gerente solo
        // puede tocar órdenes de su propio departamento (las de MTTO ven todas), igual
        // que en index(). Sin esto se podría modificar y leer una OT ajena por su id.
        $esMantenimiento = (int) $userLogueado->departamento_id === 2;
        $departamentoCreador = $orden->creador ? (int) $orden->creador->departamento_id : null;

        if (!$esMantenimiento && $userLogueado->rol !== 'admin' && $departamentoCreador !== (int) $userLogueado->departamento_id) {
            return response()->json(['error' => 'No tiene permisos sobre esta orden'], 403);
        }

        $validado = $request->validate([
            'prioridad' => ['required', 'string', Rule::in(PrioridadOT::prioridades())],
            'prioridad_motivo' => 'nullable|string',
        ]);

        // Prioridad que le correspondería según su categoría/es_seguridad (baseline para saber si "baja")
        $calculada = PrioridadOT::calcular($orden->categoria, (bool) $orden->es_seguridad);
        $ordenNuevo = PrioridadOT::PRIORIDAD_ORDEN[$validado['prioridad']];

        // Es "bajar" la prioridad cuando el nuevo orden numérico es mayor (menos urgente)
        // que el actual o que el que le correspondía por categoría/seguridad. Se toma el
        // más urgente de los dos como baseline para que bajar un override previo también
        // exija justificación.
        $baseline = min((int) $orden->prioridad_orden, $calculada['prioridad_orden']);
        $esBajaDePrioridad = $ordenNuevo > $baseline;

        if ($esBajaDePrioridad && empty($validado['prioridad_motivo'])) {
            return response()->json([
                'error' => 'prioridad_motivo es obligatorio al bajar la prioridad calculada',
            ], 422);
        }

        // Si la orden está marcada como de seguridad, nunca puede bajar de 'critica'
        if ($orden->es_seguridad && $validado['prioridad'] !== PrioridadOT::CRITICA) {
            return response()->json([
                'error' => 'Esta orden está marcada como de seguridad: no se puede bajar de prioridad crítica',
            ], 422);
        }

        $orden->prioridad = $validado['prioridad'];
        $orden->prioridad_orden = $ordenNuevo;
        $orden->prioridad_definida_por_id = $userLogueado->id;

        // Solo se pisa el motivo si vino uno nuevo, para no borrar la justificación
        // de un override anterior cuando se sube la prioridad sin comentario.
        if (!empty($validado['prioridad_motivo'])) {
            $orden->prioridad_motivo = $validado['prioridad_motivo'];
        }

        $orden->save();

        // Payload acotado: no se devuelve el modelo completo (comentarios, mensaje de
        // finalización, etc.) en un endpoint cuyo objeto es solo la prioridad.
        return response()->json([
            'id' => $orden->id,
            'prioridad' => $orden->prioridad,
            'prioridad_orden' => $orden->prioridad_orden,
            'prioridad_motivo' => $orden->prioridad_motivo,
            'prioridad_definida_por_id' => $orden->prioridad_definida_por_id,
            'es_seguridad' => (bool) $orden->es_seguridad,
            'sla_estado' => $orden->sla_estado,
            'sla_vence_at' => $orden->sla_vence_at,
            'cumplio_sla' => $orden->cumplio_sla,
        ], 200);
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
            // Categoría y marca de seguridad (§1 de la spec): determinan la prioridad automática.
            // Es "sometimes" y no "required" para no romper clientes que todavía no la mandan:
            // en ese caso se cae al default histórico ('averia' => prioridad media), igual que
            // el default de la columna. El front nuevo sí la exige por UI.
            'categoria' => ['sometimes', 'string', Rule::in(PrioridadOT::categorias())],
            'es_seguridad' => 'boolean',
        ]);

        // |file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:28120

        try {
            $userLogueado = auth()->user();

            // Calcular la prioridad automática a partir de la categoría y la marca de seguridad
            $categoria = $request->input('categoria', PrioridadOT::CAT_AVERIA);
            $esSeguridad = $request->boolean('es_seguridad');
            $prioridadCalculada = PrioridadOT::calcular($categoria, $esSeguridad);

            // Crear la orden de trabajo
            // OJO: 'descripcion' no es una columna de ordenes_trabajo (vive en la tabla
            // `descripciones`); se pasa igual porque Eloquent ignora las claves no fillable/
            // inexistentes, tal como ya se comportaba antes de este cambio (no es alcance de esta tarea).
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
                'categoria' => $categoria,
                'es_seguridad' => $esSeguridad,
                'prioridad' => $prioridadCalculada['prioridad'],
                'prioridad_orden' => $prioridadCalculada['prioridad_orden'],
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

    /**
     * Catálogos de categorías/prioridades/SLA (§7 de la spec) para alimentar el
     * modal de creación y los filtros del front, sin duplicar la tabla de mapeo en JS.
     */
    public function catalogos()
    {
        // Se incluye la prioridad resultante de cada categoría para que el front pueda
        // mostrar el preview en vivo sin duplicar el mapeo de PrioridadOT en JS.
        $categorias = collect(PrioridadOT::categorias())->map(fn ($valor) => [
            'value' => $valor,
            'label' => PrioridadOT::CATEGORIA_LABELS[$valor],
            'prioridad' => PrioridadOT::CATEGORIA_PRIORIDAD[$valor],
        ])->values();

        $prioridades = collect(PrioridadOT::prioridades())->map(fn ($valor) => [
            'value' => $valor,
            'label' => PrioridadOT::PRIORIDAD_LABELS[$valor],
            'color' => PrioridadOT::PRIORIDAD_COLORES[$valor],
            'orden' => PrioridadOT::PRIORIDAD_ORDEN[$valor],
        ])->values();

        return response()->json([
            'categorias' => $categorias,
            'prioridades' => $prioridades,
            'sla_horas' => config('ot.sla_horas'),
        ]);
    }
}
