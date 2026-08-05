<?php

namespace App\Http\Controllers;

use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Support\AlcanceOrdenes;
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

        // Performance por técnico: oculto para 'analista' y también para SyH
        // (solo lectura del tablero de seguridad, no del rendimiento de MTTO).
        if ($user->rol === 'analista' || AlcanceOrdenes::esSeguridad($user)) {
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

        $query = AlcanceOrdenes::aplicar(OrdenTrabajo::query(), $user);

        $filtroDepartamento = $validado['departamento_id'] ?? null;
        if (!empty($filtroDepartamento)) {
            $veTodosLosDepartamentos = AlcanceOrdenes::veTodosLosDepartamentos($user);

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

    // El filtro de alcance por permisos (antes duplicado acá como
    // aplicarAlcance()/veTodosLosDepartamentos()) vive centralizado en
    // App\Support\AlcanceOrdenes, compartido con OrdenTrabajoController y
    // MensajeController. Reglas (§6 de la spec + SyH):
    // - MTTO (App\Support\Departamentos::esMantenimiento) o rol 'admin' (legacy,
    //   no insertable hoy) -> ven TODOS los departamentos.
    // - Seguridad e Higiene (SyH) -> solo las OTs de seguridad (de cualquier
    //   departamento) + las propias, NO el consolidado de toda la planta.
    // - Cualquier otro usuario -> solo las OTs de su propio departamento.

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
