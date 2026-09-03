<?php

namespace App\Http\Controllers;

use App\Models\Departamento;
use App\Models\SolicitudHhee;
use App\Models\User;
use App\Support\AlcanceHhee;
use App\Support\HheeAprobadores;
use App\Support\HheeAutorizacionException;
use App\Support\HheeEstados;
use App\Support\HheeFlujo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SolicitudHheeController extends Controller
{
    /**
     * Catálogos para el front: estados, roles, tipos de hora, niveles que
     * puede firmar el usuario logueado, si tiene contingencia, tope de horas,
     * sectores, departamentos y usuarios (para el select del formulario
     * FO-008-RRH).
     *
     * 'usuarios' se resuelve ACÁ (no en un endpoint aparte
     * GET /api/hhee/usuarios): el payload es liviano (solo id/name/
     * departamento_id, sin relaciones) y catalogos() ya es la fuente única de
     * "datos de referencia" del módulo que el front carga una sola vez al
     * entrar; separarlo en otro endpoint solo para esto sumaría un round-trip
     * más sin necesidad. Si esta tabla creciera mucho (miles de usuarios) sí
     * ameritaría paginar/buscar server-side en un endpoint propio.
     *
     * 'departamentos' se deja por compatibilidad (ya no se usa para elegir
     * departamento en el form -eso ahora es 'sector', fijo-, pero el filtro
     * 'departamento_id' del listado y las tabs de aprobadores del front
     * pueden seguir necesitándolo). Si en algún momento el front deja de
     * consumirlo del todo, se puede sacar de acá.
     */
    public function catalogos()
    {
        $user = auth()->user();

        return response()->json([
            'estados' => HheeEstados::catalogo(),
            'roles_labels' => config('hhee.roles_labels'),
            // 'tipos_hora': quedó sin uso en el front tras sacar el desglose
            // por tipo de hora de la carga (ver App\Support\HheeFlujo, ahora
            // un solo total de horas por empleado). Se deja acá por si algún
            // reporte/pantalla vieja lo sigue leyendo; si se confirma que
            // nada lo consume, se puede sacar.
            'tipos_hora' => config('hhee.tipos_hora'),
            'sectores' => config('hhee.sectores'),
            'mis_niveles' => HheeAprobadores::nivelesGenerales($user),
            'es_contingencia' => HheeAprobadores::tieneRolContingenciaActivo($user),
            'max_horas_por_empleado' => (float) config('hhee.max_horas_por_empleado'),
            'departamentos' => Departamento::query()->select('id', 'nombre')->orderBy('nombre')->get(),
            'usuarios' => User::query()->select('id', 'name', 'departamento_id')->orderBy('name')->get(),
        ]);
    }

    /**
     * Listado paginado con el alcance de App\Support\AlcanceHhee y filtros
     * opcionales (estado[], fecha_desde, fecha_hasta, departamento_id,
     * sector, solicitante_id, solo_pendientes_mias).
     */
    public function index(Request $request)
    {
        // Validación de filtros (antes de esto: 'fecha_desde=abc' o
        // 'departamento_id=x' llegaban directo a whereDate()/where() y
        // reventaban en un 500 genérico en vez de un 422 claro).
        $filtros = $request->validate([
            'estado' => 'array',
            'estado.*' => ['string', Rule::in(HheeEstados::estados())],
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
            // departamento_id se mantiene (lo usan las tabs de aprobadores,
            // que siguen agrupando por departamento); 'sector' es el filtro
            // nuevo, descriptivo, de las 4 opciones fijas.
            'departamento_id' => 'nullable|integer|exists:departamentos,id',
            'sector' => ['nullable', 'string', Rule::in(config('hhee.sectores'))],
            'solicitante_id' => 'nullable|integer|exists:users,id',
            'solo_pendientes_mias' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $user = auth()->user();

            $porPagina = min((int) ($filtros['per_page'] ?? 25), 100);

            $query = SolicitudHhee::with(['solicitante:id,name', 'departamento:id,nombre', 'aprobaciones'])
                ->withCount('detalles');

            $query = AlcanceHhee::aplicar($query, $user);

            if (!empty($filtros['estado'])) {
                $query->whereIn('estado', $filtros['estado']);
            }

            if (!empty($filtros['fecha_desde'])) {
                $query->whereDate('fecha_hhee', '>=', $filtros['fecha_desde']);
            }

            if (!empty($filtros['fecha_hasta'])) {
                $query->whereDate('fecha_hhee', '<=', $filtros['fecha_hasta']);
            }

            if (!empty($filtros['departamento_id'])) {
                $query->where('departamento_id', $filtros['departamento_id']);
            }

            if (!empty($filtros['sector'])) {
                $query->where('sector', $filtros['sector']);
            }

            if (!empty($filtros['solicitante_id'])) {
                $query->where('solicitante_id', $filtros['solicitante_id']);
            }

            if ($request->boolean('solo_pendientes_mias')) {
                $query = AlcanceHhee::aplicarPendientesDeMiFirma($query, $user);
            }

            $solicitudes = $query->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($porPagina);

            return response()->json($solicitudes);
        } catch (\Exception $e) {
            Log::error('Error al listar solicitudes de HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error al listar las solicitudes'], 500);
        }
    }

    /**
     * Solicitudes pendientes de LA FIRMA del usuario logueado (para el badge
     * de campana/polling del front). '?solo_total=1' devuelve solo
     * {"total": N} sin las filas (pensado para polling frecuente del badge,
     * evita traer/serializar de más).
     */
    public function pendientes(Request $request)
    {
        try {
            $user = auth()->user();

            $query = SolicitudHhee::query();
            $query = AlcanceHhee::aplicarPendientesDeMiFirma($query, $user);

            if ($request->boolean('solo_total')) {
                return response()->json(['total' => $query->count()]);
            }

            $query->with(['solicitante:id,name', 'departamento:id,nombre'])->withCount('detalles');

            $solicitudes = $query->orderBy('fecha_hhee', 'asc')->orderBy('id', 'asc')->get();

            return response()->json([
                'total' => $solicitudes->count(),
                'solicitudes' => $solicitudes,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al listar pendientes de HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error al listar las solicitudes pendientes'], 500);
        }
    }

    public function show($id)
    {
        try {
            $solicitud = SolicitudHhee::with([
                'solicitante:id,name',
                'departamento:id,nombre',
                'rechazadoPor:id,name',
                'detalles',
                'aprobaciones.aprobador:id,name',
                'historial.usuario:id,name',
            ])->findOrFail($id);

            $user = auth()->user();

            if (!AlcanceHhee::puedeVer($user, $solicitud)) {
                return response()->json(['error' => 'No tiene permisos para ver esta solicitud'], 403);
            }

            $data = $solicitud->toArray();
            $data['flags'] = $this->flags($solicitud, $user);

            return response()->json($data);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        } catch (\Exception $e) {
            Log::error('Error al obtener la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error al obtener la solicitud'], 500);
        }
    }

    public function store(Request $request)
    {
        $validado = $this->validarPayload($request);

        try {
            $solicitud = HheeFlujo::crear($validado, auth()->user());

            return response()->json($solicitud->load(['detalles', 'aprobaciones']), 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error creando la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error creando la solicitud'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $solicitud = SolicitudHhee::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        }

        $validado = $this->validarPayload($request);

        try {
            $solicitud = HheeFlujo::actualizar($solicitud, $validado, auth()->user());

            return response()->json($solicitud->load(['detalles', 'aprobaciones']));
        } catch (HheeAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error actualizando la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error actualizando la solicitud'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $solicitud = SolicitudHhee::findOrFail($id);
            $user = auth()->user();

            if ((int) $solicitud->solicitante_id !== (int) $user->id || $solicitud->estado !== HheeEstados::BORRADOR) {
                return response()->json(['error' => 'No tiene permisos para eliminar esta solicitud'], 403);
            }

            $solicitud->delete();

            return response()->json(['message' => 'Solicitud eliminada con éxito']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        } catch (\Exception $e) {
            Log::error('Error eliminando la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error eliminando la solicitud'], 500);
        }
    }

    public function enviar($id)
    {
        try {
            $solicitud = SolicitudHhee::findOrFail($id);
            $solicitud = HheeFlujo::enviar($solicitud, auth()->user());

            return response()->json($solicitud);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        } catch (HheeAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error enviando la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error enviando la solicitud'], 500);
        }
    }

    public function aprobar(Request $request, $id)
    {
        $validado = $request->validate([
            'comentario' => 'nullable|string|max:1000',
        ]);

        try {
            $solicitud = SolicitudHhee::findOrFail($id);
            $solicitud = HheeFlujo::aprobar($solicitud, auth()->user(), $validado['comentario'] ?? null);

            return response()->json($solicitud);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        } catch (HheeAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error aprobando la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error aprobando la solicitud'], 500);
        }
    }

    public function rechazar(Request $request, $id)
    {
        $validado = $request->validate([
            'motivo' => 'required|string|max:1000',
        ]);

        try {
            $solicitud = SolicitudHhee::findOrFail($id);
            $solicitud = HheeFlujo::rechazar($solicitud, auth()->user(), $validado['motivo']);

            return response()->json($solicitud);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        } catch (HheeAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error rechazando la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error rechazando la solicitud'], 500);
        }
    }

    public function anular($id)
    {
        try {
            $solicitud = SolicitudHhee::findOrFail($id);
            $solicitud = HheeFlujo::anular($solicitud, auth()->user());

            return response()->json($solicitud);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        } catch (HheeAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error anulando la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error anulando la solicitud'], 500);
        }
    }

    public function horasReales(Request $request, $id)
    {
        $validado = $request->validate([
            'detalles' => 'required|array|min:1',
            'detalles.*.detalle_id' => 'required|integer|exists:hhee_solicitud_detalles,id',
            // Un solo número (ya no desglosado por tipo de hora): el tope de
            // config('hhee.max_horas_por_empleado') se valida aparte, en
            // App\Support\HheeFlujo::validarHorasReales().
            'detalles.*.horas_reales' => 'nullable|numeric|min:0|max:24',
            'detalles.*.fecha_realizacion' => 'required|date',
        ]);

        try {
            $solicitud = SolicitudHhee::findOrFail($id);
            $solicitud = HheeFlujo::cargarHorasReales($solicitud, $validado['detalles'], auth()->user());

            return response()->json($solicitud->load(['detalles', 'aprobaciones']));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        } catch (HheeAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error cargando horas reales de la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error cargando las horas reales'], 500);
        }
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    /**
     * Reglas de validación de store()/update() (§ FO-008-RRH). La validación
     * de negocio adicional (suma del desglose == horas del turno, tope por
     * empleado, duplicados) la hace App\Support\HheeFlujo (validarDetalles()),
     * no acá: acá solo se valida la FORMA del payload.
     *
     * departamento_id NO se valida (ni se acepta) acá a propósito: si el
     * cliente lo manda igual, $request->validate() simplemente lo descarta
     * (no rompe con 422 por campo extra, Laravel no valida "strict" por
     * default) y App\Support\HheeFlujo::crear()/actualizar() lo ignoran del
     * todo -- el departamento SIEMPRE sale de auth()->user()->departamento_id.
     * En su lugar va 'sector' (Corte/Costura/Mantenimiento/PC).
     */
    private function validarPayload(Request $request): array
    {
        return $request->validate([
            'fecha_hhee' => 'required|date',
            'sector' => ['required', 'string', Rule::in(config('hhee.sectores'))],
            'turno' => 'nullable|string|max:50',
            'observaciones' => 'nullable|string|max:1000',
            'enviar' => 'boolean',
            'detalles' => 'required|array|min:1|max:100',
            'detalles.*.nombre' => 'required|string|max:150',
            // Vínculo opcional con un usuario del sistema (ver
            // hhee_solicitud_detalles.user_id): el front lo usa para un
            // buscador de empleados, pero el campo 'nombre' libre sigue
            // siendo obligatorio (operarios sin usuario se escriben a mano).
            'detalles.*.user_id' => 'nullable|integer|exists:users,id',
            'detalles.*.motivo' => 'required|string|max:500',
            'detalles.*.necesita_transporte' => 'boolean',
            'detalles.*.localidad' => 'nullable|string|max:150|required_if:detalles.*.necesita_transporte,true',
            'detalles.*.hora_desde' => 'required|date_format:H:i',
            'detalles.*.hora_hasta' => 'required|date_format:H:i',
            // legajo, cruza_medianoche y el desglose hs_teoricas_50/100/50n/
            // 100n YA NO se piden (simplificación de la carga): si el front
            // los manda igual, no hay regla para ellos y Laravel los
            // descarta sin romper con 422 por "campo extra". El total de
            // horas teóricas lo calcula SIEMPRE el backend a partir de
            // hora_desde/hora_hasta (ver App\Support\HheeFlujo::
            // calcularHorasTeoricas()), cruza_medianoche se infiere solo del
            // horario (ver HheeFlujo::cruzaMedianoche()).
        ]);
    }

    /**
     * Flags de autorización calculados en el backend para el detalle de una
     * solicitud (el front NO debe reimplementar esta lógica).
     */
    private function flags(SolicitudHhee $solicitud, User $user): array
    {
        $esSolicitante = (int) $solicitud->solicitante_id === (int) $user->id;
        $esBorrador = $solicitud->estado === HheeEstados::BORRADOR;
        $nivelPendiente = HheeFlujo::nivelPendiente($solicitud);

        $puedeFirmar = $nivelPendiente !== null
            && (config('hhee.permitir_autoaprobacion', false) || !$esSolicitante)
            && in_array($nivelPendiente, HheeAprobadores::nivelesQuePuedeFirmar($user, $solicitud), true);

        return [
            'puede_editar' => $esBorrador && $esSolicitante,
            'puede_enviar' => $esBorrador && $esSolicitante && $solicitud->detalles->count() > 0,
            'puede_aprobar' => $puedeFirmar,
            'puede_rechazar' => $puedeFirmar,
            'puede_anular' => $esSolicitante && !HheeEstados::esTerminal($solicitud->estado),
            'puede_cargar_reales' => $esSolicitante && $solicitud->estado === HheeEstados::APROBADA,
            'nivel_pendiente' => $nivelPendiente,
        ];
    }
}
