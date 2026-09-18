<?php

namespace App\Http\Controllers;

use App\Models\Departamento;
use App\Models\OpenIssue;
use App\Models\User;
use App\Support\AlcanceOpenIssues;
use App\Support\ArchivoOrden;
use App\Support\OpenIssueAutorizacionException;
use App\Support\OpenIssueEstados;
use App\Support\OpenIssueFlujo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OpenIssueController extends Controller
{
    /**
     * Catálogos para el front: estados, prioridades, tipos de actualización,
     * departamentos y usuarios (para los selectores del alta/involucrados).
     *
     * 'usuarios' se resuelve ACÁ (no en un endpoint aparte), mismo criterio y
     * justificación que SolicitudHheeController::catalogos(): payload
     * liviano, se carga una sola vez al entrar; si algún día hay miles de
     * users se separa en un endpoint paginado.
     *
     * Nunca devuelve 403: solo requiere estar autenticado.
     */
    public function catalogos()
    {
        $user = auth()->user();

        return response()->json([
            'estados' => OpenIssueEstados::catalogo(),
            'prioridades' => OpenIssueEstados::catalogoPrioridades(),
            'tipos_actualizacion' => OpenIssueEstados::TIPOS_ACTUALIZACION_LABELS,
            'departamentos' => Departamento::query()->select('id', 'nombre')->orderBy('nombre')->get(),
            'usuarios' => User::query()->select('id', 'name', 'departamento_id')->orderBy('name')->get(),
            'prioridad_default' => config('open_issues.prioridad_default'),
            'adjunto' => config('open_issues.adjunto'),
            'flags' => [
                'es_gerente' => $user->rol === 'gerente',
                'puede_ver_todos' => AlcanceOpenIssues::veTodos($user),
                'mi_user_id' => $user->id,
                'mi_departamento_id' => $user->departamento_id,
            ],
        ]);
    }

    /**
     * Issues NO cerrados donde el usuario logueado PARTICIPA (tiene fila en
     * oi_involucrados), para el badge de campana/polling del front (§5.13).
     * '?solo_total=1' devuelve solo {"total": N} sin las filas.
     */
    public function pendientes(Request $request)
    {
        try {
            $user = auth()->user();

            $query = OpenIssue::query();
            $query = AlcanceOpenIssues::aplicarParticipo($query, $user);
            $query->where('estado', '!=', OpenIssueEstados::CERRADO);

            if ($request->boolean('solo_total')) {
                return response()->json(['total' => $query->count()]);
            }

            // Total real (no cerrados donde participo), independiente del limit(50) de abajo.
            $total = (clone $query)->count();

            $query->with(['creador:id,name', 'departamentoDestino:id,nombre', 'involucrados.usuario:id,name'])
                ->withCount(['involucrados', 'actualizaciones'])
                ->withMax('actualizaciones', 'created_at');

            $issues = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit(50)->get();

            return response()->json([
                'total' => $total,
                'issues' => $issues->map(fn (OpenIssue $i) => $this->filaListado($i))->values(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error al listar los Open Issues pendientes: ' . $e->getMessage());
            return response()->json(['error' => 'Error al listar los issues pendientes'], 500);
        }
    }

    /**
     * Listado paginado con el alcance de App\Support\AlcanceOpenIssues y
     * filtros opcionales.
     */
    public function index(Request $request)
    {
        $filtros = $request->validate([
            'estado' => 'array',
            'estado.*' => ['string', Rule::in(OpenIssueEstados::estados())],
            'prioridad' => ['nullable', 'string', Rule::in(OpenIssueEstados::prioridades())],
            'departamento_destino_id' => 'nullable|integer|exists:departamentos,id',
            'creador_id' => 'nullable|integer|exists:users,id',
            'involucrado_id' => 'nullable|integer|exists:users,id',
            'mios' => 'nullable|boolean',
            'participo' => 'nullable|boolean',
            'texto' => 'nullable|string|max:200',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $user = auth()->user();

            $porPagina = min((int) ($filtros['per_page'] ?? config('open_issues.per_page_default')), config('open_issues.per_page_max'));

            $query = OpenIssue::with([
                'creador:id,name',
                'departamentoDestino:id,nombre',
                'involucrados.usuario:id,name',
            ])
                ->withCount(['involucrados', 'actualizaciones'])
                ->withMax('actualizaciones', 'created_at');

            $query = AlcanceOpenIssues::aplicar($query, $user);

            if (!empty($filtros['estado'])) {
                $query->whereIn('estado', $filtros['estado']);
            }

            if (!empty($filtros['prioridad'])) {
                $query->where('prioridad', $filtros['prioridad']);
            }

            if (!empty($filtros['departamento_destino_id'])) {
                $query->where('departamento_destino_id', $filtros['departamento_destino_id']);
            }

            if (!empty($filtros['creador_id'])) {
                $query->where('creador_id', $filtros['creador_id']);
            }

            if (!empty($filtros['involucrado_id'])) {
                $involucradoId = $filtros['involucrado_id'];
                $query->whereHas('involucrados', fn ($q) => $q->where('user_id', $involucradoId));
            }

            if ($request->boolean('mios')) {
                $query->where('creador_id', $user->id);
            }

            if ($request->boolean('participo')) {
                $query = AlcanceOpenIssues::aplicarParticipo($query, $user);
            }

            if (!empty($filtros['fecha_desde'])) {
                $query->whereDate('created_at', '>=', $filtros['fecha_desde']);
            }

            if (!empty($filtros['fecha_hasta'])) {
                $query->whereDate('created_at', '<=', $filtros['fecha_hasta']);
            }

            if (!empty($filtros['texto'])) {
                $t = $filtros['texto'];
                $query->where(fn ($q) => $q->where('titulo', 'like', "%{$t}%")->orWhere('descripcion', 'like', "%{$t}%"));
            }

            $paginador = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($porPagina);

            return response()->json($paginador->through(fn (OpenIssue $i) => $this->filaListado($i)));
        } catch (\Exception $e) {
            Log::error('Error al listar los Open Issues: ' . $e->getMessage());
            return response()->json(['error' => 'Error al listar los issues'], 500);
        }
    }

    public function store(Request $request)
    {
        $validado = $request->validate([
            'titulo' => 'required|string|max:200',
            'descripcion' => 'nullable|string|max:4000',
            'departamento_destino_id' => 'required|integer|exists:departamentos,id',
            'prioridad' => ['nullable', 'string', Rule::in(OpenIssueEstados::prioridades())],
            'involucrados_ids' => 'nullable|array|max:200',
            'involucrados_ids.*' => 'integer|distinct|exists:users,id',
            'departamentos_ids' => 'nullable|array|max:20',
            'departamentos_ids.*' => 'integer|distinct|exists:departamentos,id',
            'texto_inicial' => 'nullable|string|max:4000',
            'archivo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:5120',
        ]);

        try {
            $user = auth()->user();

            $adjunto = $this->guardarAdjunto($request);

            try {
                $issue = OpenIssueFlujo::crear($validado, $user, $adjunto);
            } catch (ValidationException|OpenIssueAutorizacionException $e) {
                // El archivo ya se movió a disco antes de que crear() fallara: no dejarlo huérfano.
                $this->eliminarAdjuntoSiExiste($adjunto);
                throw $e;
            }

            return response()->json($this->detalle($issue->load($this->eagerLoadDetalle()), $user), 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error creando el Open Issue: ' . $e->getMessage());
            return response()->json(['error' => 'Error creando el issue'], 500);
        }
    }

    public function show($id)
    {
        try {
            $issue = OpenIssue::with($this->eagerLoadDetalle())->findOrFail($id);

            $user = auth()->user();

            if (!AlcanceOpenIssues::puedeVer($user, $issue)) {
                return response()->json(['error' => 'No tiene permisos para ver este issue'], 403);
            }

            return response()->json($this->detalle($issue, $user));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Issue no encontrado'], 404);
        } catch (\Exception $e) {
            Log::error('Error al obtener el Open Issue: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener el issue'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validado = $request->validate([
            'titulo' => 'required|string|max:200',
            'descripcion' => 'nullable|string|max:4000',
            'prioridad' => ['nullable', 'string', Rule::in(OpenIssueEstados::prioridades())],
            'departamento_destino_id' => 'nullable|integer|exists:departamentos,id',
        ]);

        try {
            $issue = OpenIssue::findOrFail($id);
            $user = auth()->user();

            $issue = OpenIssueFlujo::actualizar($issue, $validado, $user);

            return response()->json($this->detalle($issue->load($this->eagerLoadDetalle()), $user));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Issue no encontrado'], 404);
        } catch (OpenIssueAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error actualizando el Open Issue: ' . $e->getMessage());
            return response()->json(['error' => 'Error actualizando el issue'], 500);
        }
    }

    public function storeActualizacion(Request $request, $id)
    {
        $validado = $request->validate([
            'texto' => 'nullable|string|max:4000',
            'nuevo_estado' => ['nullable', 'string', Rule::in(OpenIssueEstados::ESTADOS_MANUALES)],
            'archivo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:5120',
        ]);

        try {
            $user = auth()->user();

            // Orden obligatorio (§4.2): primero autorización, RECIÉN DESPUÉS se
            // mueve el archivo a disco, para no dejar adjuntos huérfanos si el
            // usuario no tiene permiso.
            $issue = OpenIssue::findOrFail($id);
            OpenIssueFlujo::asegurarPuedeEscribir($issue, $user);

            $adjunto = $this->guardarAdjunto($request);

            try {
                $actualizacion = OpenIssueFlujo::agregarActualizacion($issue, $validado, $user, $adjunto);
            } catch (ValidationException|OpenIssueAutorizacionException $e) {
                // Idem store(): el adjunto ya está en disco, no dejarlo huérfano si agregarActualizacion() falla.
                $this->eliminarAdjuntoSiExiste($adjunto);
                throw $e;
            }

            return response()->json([
                'actualizacion' => $this->actualizacionArray($actualizacion),
                'issue' => $this->detalle($issue->fresh($this->eagerLoadDetalle()), $user),
            ], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Issue no encontrado'], 404);
        } catch (OpenIssueAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error agregando actualización del Open Issue: ' . $e->getMessage());
            return response()->json(['error' => 'Error agregando la actualización del issue'], 500);
        }
    }

    public function cerrar(Request $request, $id)
    {
        $validado = $request->validate([
            'texto' => 'nullable|string|max:4000',
        ]);

        try {
            $issue = OpenIssue::findOrFail($id);
            $user = auth()->user();

            $issue = OpenIssueFlujo::cerrar($issue, $user, $validado['texto'] ?? null);

            return response()->json(['issue' => $this->detalle($issue->load($this->eagerLoadDetalle()), $user)]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Issue no encontrado'], 404);
        } catch (OpenIssueAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error cerrando el Open Issue: ' . $e->getMessage());
            return response()->json(['error' => 'Error cerrando el issue'], 500);
        }
    }

    public function reabrir(Request $request, $id)
    {
        $validado = $request->validate([
            'texto' => 'nullable|string|max:4000',
        ]);

        try {
            $issue = OpenIssue::findOrFail($id);
            $user = auth()->user();

            $issue = OpenIssueFlujo::reabrir($issue, $user, $validado['texto'] ?? null);

            return response()->json(['issue' => $this->detalle($issue->load($this->eagerLoadDetalle()), $user)]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Issue no encontrado'], 404);
        } catch (OpenIssueAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error reabriendo el Open Issue: ' . $e->getMessage());
            return response()->json(['error' => 'Error reabriendo el issue'], 500);
        }
    }

    public function storeInvolucrados(Request $request, $id)
    {
        $validado = $request->validate([
            'user_ids' => 'nullable|array|max:200',
            'user_ids.*' => 'integer|distinct|exists:users,id',
            'departamento_ids' => 'nullable|array|max:20',
            'departamento_ids.*' => 'integer|distinct|exists:departamentos,id',
        ]);

        if (empty($validado['user_ids']) && empty($validado['departamento_ids'])) {
            throw ValidationException::withMessages([
                'user_ids' => 'Indicá al menos un usuario o un departamento.',
            ]);
        }

        try {
            $issue = OpenIssue::findOrFail($id);
            $user = auth()->user();

            $r = OpenIssueFlujo::agregarInvolucrados(
                $issue,
                $validado['user_ids'] ?? [],
                $validado['departamento_ids'] ?? [],
                $user
            );

            return response()->json([
                'agregados' => $r['agregados'],
                'ignorados' => $r['ignorados'],
                'issue' => $this->detalle($issue->fresh($this->eagerLoadDetalle()), $user),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Issue no encontrado'], 404);
        } catch (OpenIssueAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error agregando involucrados del Open Issue: ' . $e->getMessage());
            return response()->json(['error' => 'Error agregando involucrados del issue'], 500);
        }
    }

    public function destroyInvolucrado($id, $userId)
    {
        try {
            $issue = OpenIssue::findOrFail($id);
            $user = auth()->user();

            OpenIssueFlujo::quitarInvolucrado($issue, (int) $userId, $user);

            return response()->json([
                'message' => 'Involucrado quitado',
                'issue' => $this->detalle($issue->fresh($this->eagerLoadDetalle()), $user),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Issue no encontrado'], 404);
        } catch (OpenIssueAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error quitando involucrado del Open Issue: ' . $e->getMessage());
            return response()->json(['error' => 'Error quitando involucrado del issue'], 500);
        }
    }

    // =========================================================================
    // Helpers privados: shapes exactos (§4.3)
    // =========================================================================

    /**
     * Normaliza a ISO-8601 tanto valores ya-Carbon (created_at, casteado por
     * Eloquent) como el string crudo que devuelve withMax() (agregado que NO
     * pasa por $casts del modelo, ver filaListado()).
     */
    private function fechaIso($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if ($valor instanceof \Carbon\Carbon) {
            return $valor->toJSON();
        }

        return \Carbon\Carbon::parse($valor)->toJSON();
    }

    private function eagerLoadDetalle(): array
    {
        return [
            'creador:id,name,departamento_id',
            'departamentoDestino:id,nombre',
            'cerradoPor:id,name',
            'involucrados.usuario:id,name,departamento_id',
            'involucrados.usuario.departamento:id,nombre',
            'involucrados.departamento:id,nombre',
            'involucrados.agregadoPor:id,name',
            'actualizaciones.autor:id,name',
        ];
    }

    /**
     * Item de data[] del index y de pendientes.issues[].
     */
    private function filaListado(OpenIssue $i): array
    {
        return [
            'id' => $i->id,
            'titulo' => $i->titulo,
            'estado' => $i->estado,
            'prioridad' => $i->prioridad,
            'departamento_destino_id' => $i->departamento_destino_id,
            'departamento_destino' => $i->departamentoDestino ? [
                'id' => $i->departamentoDestino->id,
                'nombre' => $i->departamentoDestino->nombre,
            ] : null,
            'creador_id' => $i->creador_id,
            'creador' => $i->creador ? ['id' => $i->creador->id, 'name' => $i->creador->name] : null,
            'involucrados_count' => $i->involucrados_count,
            'actualizaciones_count' => $i->actualizaciones_count,
            'involucrados_preview' => $i->involucrados->take(5)->map(fn ($inv) => [
                'id' => $inv->user_id,
                'name' => $inv->usuario->name ?? null,
            ])->values(),
            'ultima_actualizacion_at' => $this->fechaIso($i->actualizaciones_max_created_at ?? $i->created_at),
            'fecha_cierre' => optional($i->fecha_cierre)->toJSON(),
            'created_at' => optional($i->created_at)->toJSON(),
        ];
    }

    /**
     * Respuesta de show/store/update/cerrar/reabrir.
     */
    private function detalle(OpenIssue $i, User $user): array
    {
        $flags = $this->flags($i, $user);

        return [
            'id' => $i->id,
            'titulo' => $i->titulo,
            'descripcion' => $i->descripcion,
            'estado' => $i->estado,
            'prioridad' => $i->prioridad,
            'departamento_destino_id' => $i->departamento_destino_id,
            'departamento_destino' => $i->departamentoDestino ? [
                'id' => $i->departamentoDestino->id,
                'nombre' => $i->departamentoDestino->nombre,
            ] : null,
            'creador_id' => $i->creador_id,
            'creador' => $i->creador ? [
                'id' => $i->creador->id,
                'name' => $i->creador->name,
                'departamento_id' => $i->creador->departamento_id,
            ] : null,
            'fecha_cierre' => optional($i->fecha_cierre)->toJSON(),
            'cerrado_por_id' => $i->cerrado_por_id,
            'cerrado_por' => $i->cerradoPor ? ['id' => $i->cerradoPor->id, 'name' => $i->cerradoPor->name] : null,
            'fecha_reapertura' => optional($i->fecha_reapertura)->toJSON(),
            'created_at' => optional($i->created_at)->toJSON(),
            'updated_at' => optional($i->updated_at)->toJSON(),
            // Igual criterio que filaListado(): máximo created_at de las actualizaciones
            // ya cargadas, o el created_at del issue si todavía no tiene ninguna.
            'ultima_actualizacion_at' => $this->fechaIso($i->actualizaciones->max('created_at') ?: $i->created_at),
            'involucrados' => $i->involucrados->map(function ($inv) use ($i, $flags) {
                $esCreador = (int) $inv->user_id === (int) $i->creador_id;

                return [
                    'id' => $inv->id,
                    'user_id' => $inv->user_id,
                    'name' => $inv->usuario->name ?? null,
                    'departamento_id' => $inv->usuario->departamento_id ?? null,
                    'departamento_nombre' => $inv->usuario->departamento->nombre ?? null,
                    'origen' => $inv->origen,
                    'departamento_origen_id' => $inv->departamento_id,
                    'departamento_origen_nombre' => $inv->departamento->nombre ?? null,
                    'agregado_por_id' => $inv->agregado_por_id,
                    'agregado_por_name' => $inv->agregadoPor->name ?? null,
                    'created_at' => optional($inv->created_at)->toJSON(),
                    'es_creador' => $esCreador,
                    'puede_quitar' => $flags['puede_quitar_involucrados'] && !$esCreador,
                ];
            })->values(),
            'actualizaciones' => $i->actualizaciones->map(fn ($a) => $this->actualizacionArray($a))->values(),
            'flags' => $flags,
        ];
    }

    private function actualizacionArray($a): array
    {
        return [
            'id' => $a->id,
            'tipo' => $a->tipo,
            'tipo_label' => OpenIssueEstados::TIPOS_ACTUALIZACION_LABELS[$a->tipo] ?? $a->tipo,
            'texto' => $a->texto,
            'estado_anterior' => $a->estado_anterior,
            'estado_nuevo' => $a->estado_nuevo,
            'archivo_nombre' => $a->archivo,
            'archivo_url' => ArchivoOrden::url($a->archivo),
            'mime_type' => $a->mime_type,
            'created_at' => optional($a->created_at)->toJSON(),
            'autor' => $a->autor ? ['id' => $a->autor->id, 'name' => $a->autor->name] : null,
        ];
    }

    /**
     * Flags de autorización calculados en el backend (el front NO recalcula
     * permisos, solo los lee).
     */
    private function flags(OpenIssue $i, User $user): array
    {
        $esCreador = (int) $i->creador_id === (int) $user->id;
        $cerrado = $i->estado === OpenIssueEstados::CERRADO;
        $puedeEscribir = AlcanceOpenIssues::puedeEscribir($user, $i);

        return [
            'puede_ver' => AlcanceOpenIssues::puedeVer($user, $i),
            'puede_editar' => $esCreador && !$cerrado,
            'puede_actualizar' => $puedeEscribir && !$cerrado,
            'puede_cerrar' => $puedeEscribir && !$cerrado,
            'puede_reabrir' => $puedeEscribir && $cerrado,
            'puede_involucrar' => $puedeEscribir && !$cerrado,
            'puede_quitar_involucrados' => $puedeEscribir && !$cerrado,
            'es_creador' => $esCreador,
            'es_involucrado' => AlcanceOpenIssues::esInvolucrado($user, $i),
        ];
    }

    /**
     * Guarda el adjunto (si vino) con App\Support\ArchivoOrden::store(), y
     * devuelve el shape que espera App\Support\OpenIssueFlujo. NO toca el
     * filesystem si no hay archivo.
     */
    private function guardarAdjunto(Request $request): array
    {
        if (!$request->hasFile('archivo')) {
            return ['archivo' => null, 'mime_type' => null];
        }

        return [
            'mime_type' => $request->file('archivo')->getMimeType(),
            'archivo' => ArchivoOrden::store($request->file('archivo'), 'open-issue'),
        ];
    }

    /**
     * Borra del disco el adjunto ya guardado por guardarAdjunto() cuando la
     * operación que lo iba a asociar (crear/agregarActualizacion) termina
     * fallando: evita huérfanos en storage/archivos/ (§4.2, revisión #4).
     */
    private function eliminarAdjuntoSiExiste(array $adjunto): void
    {
        if (!empty($adjunto['archivo'])) {
            File::delete(public_path('storage/archivos/' . $adjunto['archivo']));
        }
    }
}
