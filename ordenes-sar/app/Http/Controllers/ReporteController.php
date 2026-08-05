<?php

namespace App\Http\Controllers;

use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Support\PrioridadOT;
use App\Support\ReporteQueries;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de reportes/KPIs de OTs (§6 de la spec). Todo el cálculo pesado
 * vive en App\Support\ReporteQueries (agregado en SQL, sin iterar colecciones
 * en PHP); este controller solo resuelve alcance/permisos, filtros de fecha
 * y arma el payload de cada endpoint.
 */
class ReporteController extends Controller
{
    /**
     * GET /api/reportes/resumen
     * KPIs agregados del alcance visible por el usuario en el rango pedido.
     */
    public function resumen(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = $this->construirQueryAlcance($request, $user);
        if ($query instanceof JsonResponse) {
            return $query;
        }

        [$inicio, $fin] = $this->resolverRangoFechas($request);

        $resumen = ReporteQueries::resumen($query, $inicio, $fin);

        return response()->json([
            'rango' => ['fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $fin->toDateString()],
            'resumen' => $resumen,
            // Catálogos para que el front no duplique la tabla de mapeo (§6)
            'categorias' => PrioridadOT::CATEGORIA_LABELS,
            'prioridades' => PrioridadOT::PRIORIDAD_LABELS,
            'prioridad_colores' => PrioridadOT::PRIORIDAD_COLORES,
            'sla_horas' => config('ot.sla_horas'),
        ]);
    }

    /**
     * GET /api/reportes/departamentos
     * Una fila por departamento con las mismas métricas de resumen().
     */
    public function departamentos(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = $this->construirQueryAlcance($request, $user);
        if ($query instanceof JsonResponse) {
            return $query;
        }

        [$inicio, $fin] = $this->resolverRangoFechas($request);

        return response()->json([
            'rango' => ['fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $fin->toDateString()],
            'departamentos' => ReporteQueries::porDepartamento($query, $inicio, $fin),
        ]);
    }

    /**
     * GET /api/reportes/mantenimiento
     * Una fila por técnico de MTTO. Oculto para 'analista' (§6).
     */
    public function mantenimiento(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->rol === 'analista') {
            return response()->json(['error' => 'No tiene permisos para ver este reporte'], 403);
        }

        $query = $this->construirQueryAlcance($request, $user);
        if ($query instanceof JsonResponse) {
            return $query;
        }

        [$inicio, $fin] = $this->resolverRangoFechas($request);

        return response()->json([
            'rango' => ['fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $fin->toDateString()],
            'tecnicos' => ReporteQueries::porTecnicoMantenimiento($query, $inicio, $fin),
        ]);
    }

    /**
     * GET /api/reportes/tendencia
     * Serie por semana o mes (?agrupar_por=semana|mes, default 'semana').
     */
    public function tendencia(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = $this->construirQueryAlcance($request, $user);
        if ($query instanceof JsonResponse) {
            return $query;
        }

        $validado = $request->validate([
            'agrupar_por' => 'nullable|string|in:semana,mes',
        ]);
        $agruparPor = $validado['agrupar_por'] ?? 'semana';

        [$inicio, $fin] = $this->resolverRangoFechas($request);

        return response()->json([
            'rango' => ['fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $fin->toDateString()],
            'agrupar_por' => $agruparPor,
            'tendencia' => ReporteQueries::tendencia($query, $agruparPor, $inicio, $fin),
        ]);
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    /**
     * Arma la query base de ordenes_trabajo aplicando el alcance de permisos
     * del usuario (aplicarAlcance) y, si vino, el filtro opcional de
     * departamento_id (validando que el usuario tenga permiso para pedirlo).
     * Devuelve el query builder listo para pasar a ReporteQueries, o un
     * JsonResponse de error (403/422) si algo no es válido: el caller debe
     * chequear "instanceof JsonResponse" antes de seguir.
     *
     * @return \Illuminate\Database\Eloquent\Builder|JsonResponse
     */
    private function construirQueryAlcance(Request $request, User $user)
    {
        $validado = $request->validate([
            'departamento_id' => 'nullable|integer|exists:departamentos,id',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $query = $this->aplicarAlcance(OrdenTrabajo::query(), $user);

        $filtroDepartamento = $validado['departamento_id'] ?? null;
        if (!empty($filtroDepartamento)) {
            $veTodosLosDepartamentos = $this->veTodosLosDepartamentos($user);

            // Un gerente (fuera de MTTO) o un analista solo pueden pedir SU propio
            // departamento; si piden otro, 403 (§6 de la spec)
            if (!$veTodosLosDepartamentos && (int) $filtroDepartamento !== (int) $user->departamento_id) {
                return response()->json(['error' => 'No tiene permisos para ver ese departamento'], 403);
            }

            $query->whereHas('creador', function ($q) use ($filtroDepartamento) {
                $q->where('departamento_id', $filtroDepartamento);
            });
        }

        return $query;
    }

    /**
     * Filtro de alcance por permisos (§6 de la spec), reutilizado por los 4
     * endpoints. Reglas:
     * - departamento_id = 2 (MTTO) o rol = 'admin' -> ven TODOS los departamentos
     *   (no se agrega ningún whereHas).
     * - Cualquier otro usuario (gerente, group_leader fuera de MTTO, analista)
     *   -> solo ve las OTs cuyo creador pertenece a su propio departamento.
     *
     * OJO: el rol 'admin' no es un valor insertable hoy en users.rol (el enum de
     * la migración original solo permite gerente/group_leader/team_member/analista),
     * pero se deja el chequeo por si en el futuro se habilita, sin que rompa nada
     * mientras tanto (nunca va a matchear). El acceso total real hoy lo dan los
     * usuarios de MTTO (departamento_id = 2).
     */
    private function aplicarAlcance($query, User $user)
    {
        if ($this->veTodosLosDepartamentos($user)) {
            return $query;
        }

        $departamentoId = $user->departamento_id;

        return $query->whereHas('creador', function ($q) use ($departamentoId) {
            $q->where('departamento_id', $departamentoId);
        });
    }

    /**
     * True si el usuario ve todos los departamentos: MTTO (departamento_id = 2)
     * o rol 'admin' (ver nota de aplicarAlcance() sobre por qué se deja el check).
     */
    private function veTodosLosDepartamentos(User $user): bool
    {
        return (int) $user->departamento_id === 2 || $user->rol === 'admin';
    }

    /**
     * Resuelve fecha_inicio/fecha_fin del request; default: últimos 30 días
     * (hoy inclusive). La validación de formato ya se hizo en construirQueryAlcance().
     *
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolverRangoFechas(Request $request): array
    {
        $fin = $request->filled('fecha_fin')
            ? Carbon::parse($request->input('fecha_fin'))
            : Carbon::now('America/Argentina/Buenos_Aires');

        $inicio = $request->filled('fecha_inicio')
            ? Carbon::parse($request->input('fecha_inicio'))
            : $fin->copy()->subDays(29);

        return [$inicio, $fin];
    }
}
