<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Encapsula el SQL de agregación de los reportes (§5 de la spec). Todo el
 * cálculo de tiempos y promedios se hace en el motor (DATEDIFF/AVG/
 * PERCENTILE_CONT), nunca iterando colecciones en PHP.
 *
 * IMPORTANTE — contrato con el caller (controller):
 * El parámetro $query debe venir filtrado SOLO por alcance (permisos de
 * departamento/rol, vía el futuro OrdenTrabajoController::aplicarAlcance()),
 * *sin* filtro de fechas todavía. El rango de fechas se pasa aparte
 * ($fechaInicio/$fechaFin) porque cada métrica de §5 filtra por una columna
 * de fecha distinta (created_at para "total", fecha_finalizacion para
 * "finalizadas", o ninguna para "activas"/"vencidas"); si el filtro de
 * fechas ya viniera aplicado sobre created_at en el query recibido, las
 * métricas "activas" y "finalizadas" quedarían mal (activas no debe
 * filtrarse por rango, y finalizadas debe filtrarse por fecha_finalizacion,
 * no por created_at). Cada método clona $query y agrega sus propios filtros.
 *
 * $fechaInicio/$fechaFin aceptan Carbon o string parseable; se normalizan
 * internamente a inicio/fin de día.
 */
class ReporteQueries
{
    /**
     * Estados posibles de una OT (enum de la tabla ordenes_trabajo). Se
     * hardcodea acá porque no existe todavía una clase de constantes de
     * estados en el proyecto; se usa solo para completar con 0 los estados
     * sin filas en el rango.
     */
    private const ESTADOS = ['creada', 'aprobada', 'asignada', 'en_proceso', 'finalizada'];

    // =====================================================================
    // RESUMEN (§6 GET /api/reportes/resumen)
    // =====================================================================

    /**
     * KPIs agregados de §5 para el alcance/rango dado.
     *
     * @param EloquentBuilder|QueryBuilder $query Query sobre ordenes_trabajo, ya filtrado por alcance.
     * @return array{
     *   total:int, activas:int, finalizadas:int, vencidas:int,
     *   por_estado:array<string,int>, por_prioridad:array<string,int>,
     *   tiempo_respuesta_prom:?float, tiempo_respuesta_mediana:?float,
     *   tiempo_resolucion_prom:?float, tiempo_resolucion_mediana:?float,
     *   tiempo_total_prom:?float,
     *   cumplimiento_sla_pct:?float, sin_datos:int
     * }
     */
    public static function resumen($query, $fechaInicio, $fechaFin): array
    {
        [$inicio, $fin] = self::normalizarRango($fechaInicio, $fechaFin);

        $porEstado = self::conteoPorColumna($query, 'estado', $inicio, $fin, self::ESTADOS);
        $porPrioridad = self::conteoPorColumna($query, 'prioridad', $inicio, $fin, PrioridadOT::prioridades());

        $activasRow = self::agregarActivasVencidas($query);

        $respuesta = self::agregarTiempoRespuesta($query, $inicio, $fin);
        $resolucion = self::agregarTiempoResolucion($query, $inicio, $fin);

        return [
            'total' => (int) $respuesta->total,
            'activas' => (int) $activasRow->activas,
            'finalizadas' => (int) $resolucion->finalizadas,
            'vencidas' => (int) $activasRow->vencidas,
            'por_estado' => $porEstado,
            'por_prioridad' => $porPrioridad,
            'tiempo_respuesta_prom' => self::minutosAHoras($respuesta->prom_respuesta_minutos),
            // OJO con el nombre del alias: conMedianaParticionada() genera "mediana_{columna}",
            // es decir "mediana_minutos_respuesta" / "mediana_minutos_resolucion" (no al revés).
            'tiempo_respuesta_mediana' => self::minutosAHoras($respuesta->mediana_minutos_respuesta),
            'tiempo_resolucion_prom' => self::minutosAHoras($resolucion->prom_resolucion_minutos),
            'tiempo_resolucion_mediana' => self::minutosAHoras($resolucion->mediana_minutos_resolucion),
            'tiempo_total_prom' => self::minutosAHoras($resolucion->prom_total_minutos),
            'cumplimiento_sla_pct' => self::porcentaje($respuesta->cumplieron_sla, $respuesta->asignadas),
            'sin_datos' => (int) $respuesta->sin_datos,
        ];
    }

    // =====================================================================
    // POR DEPARTAMENTO (§6 GET /api/reportes/departamentos)
    // =====================================================================

    /**
     * Una fila por departamento del creador, con las mismas métricas de resumen().
     * El departamento sale de ordenes_trabajo.usuario_id -> users.departamento_id
     * -> departamentos (NO existe ordenes_trabajo.departamento_id).
     *
     * @return list<array{
     *   departamento_id:int, departamento_nombre:string,
     *   total:int, activas:int, finalizadas:int, vencidas:int,
     *   tiempo_respuesta_prom:?float, tiempo_respuesta_mediana:?float,
     *   tiempo_resolucion_prom:?float, tiempo_resolucion_mediana:?float,
     *   cumplimiento_sla_pct:?float, sin_datos:int
     * }>
     */
    public static function porDepartamento($query, $fechaInicio, $fechaFin): array
    {
        [$inicio, $fin] = self::normalizarRango($fechaInicio, $fechaFin);

        $conJoinDepto = fn () => (clone $query)
            ->join('users as ot_creador', 'ot_creador.id', '=', 'ordenes_trabajo.usuario_id')
            ->join('departamentos as ot_depto', 'ot_depto.id', '=', 'ot_creador.departamento_id');

        // --- activas / vencidas: ignoran el rango de fechas (snapshot actual) ---
        $activasBase = $conJoinDepto()
            ->where('ordenes_trabajo.estado', '!=', 'finalizada')
            ->select(['ot_depto.id as departamento_id', 'ot_depto.nombre as departamento_nombre'])
            ->selectRaw('ordenes_trabajo.fecha_asignacion')
            ->selectRaw(self::vencimientoSql() . ' as vencimiento');

        $activasPorDepto = DB::query()->fromSub($activasBase, 'b')
            ->select(['departamento_id', 'departamento_nombre'])
            ->selectRaw('
                COUNT(*) as activas,
                SUM(CASE WHEN fecha_asignacion IS NULL AND vencimiento < ' . self::ahoraSql() . ' THEN 1 ELSE 0 END) as vencidas
            ')
            ->groupBy('departamento_id', 'departamento_nombre')
            ->get()
            ->keyBy('departamento_id');

        // --- tiempo de respuesta / sin_datos / cumplimiento SLA: scope = creadas en el rango ---
        $respuestaBase = $conJoinDepto()
            ->whereBetween('ordenes_trabajo.created_at', [$inicio, $fin])
            ->select(['ot_depto.id as departamento_id', 'ot_depto.nombre as departamento_nombre'])
            ->selectRaw('ordenes_trabajo.estado, ordenes_trabajo.fecha_asignacion')
            ->selectRaw('DATEDIFF(MINUTE, ' . self::baseSlaSql() . ', ordenes_trabajo.fecha_asignacion) as minutos_respuesta')
            ->selectRaw(self::vencimientoSql() . ' as vencimiento');

        // OJO: acá se usa addSelect() (no select()) porque conMedianaParticionada() ya dejó
        // cargada la columna MAX(mediana_...) vía selectRaw(); select() resetea $columns
        // (Query\Builder::select() hace $this->columns = [] antes de agregar lo nuevo) y
        // pisaría esa columna. addSelect() sí acumula.
        $respuestaPorDepto = self::conMedianaParticionada($respuestaBase, 'minutos_respuesta', ['departamento_id'])
            ->addSelect(['departamento_id', 'departamento_nombre'])
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'finalizada' AND fecha_asignacion IS NULL THEN 1 ELSE 0 END) as sin_datos,
                SUM(CASE WHEN fecha_asignacion IS NOT NULL THEN 1 ELSE 0 END) as asignadas,
                SUM(CASE WHEN fecha_asignacion IS NOT NULL AND fecha_asignacion <= vencimiento THEN 1 ELSE 0 END) as cumplieron_sla,
                AVG(CASE WHEN fecha_asignacion IS NOT NULL THEN CAST(minutos_respuesta AS FLOAT) END) as prom_respuesta_minutos
            ")
            ->groupBy('departamento_id', 'departamento_nombre')
            ->get()
            ->keyBy('departamento_id');

        // --- finalizadas / tiempo de resolución: scope = finalizadas en el rango (por fecha_finalizacion) ---
        $resolucionBase = $conJoinDepto()
            ->where('ordenes_trabajo.estado', 'finalizada')
            ->whereBetween('ordenes_trabajo.fecha_finalizacion', [$inicio, $fin])
            ->select(['ot_depto.id as departamento_id', 'ot_depto.nombre as departamento_nombre'])
            ->selectRaw('DATEDIFF(MINUTE, ordenes_trabajo.fecha_asignacion, ordenes_trabajo.fecha_finalizacion) as minutos_resolucion');

        $resolucionPorDepto = self::conMedianaParticionada($resolucionBase, 'minutos_resolucion', ['departamento_id'])
            ->addSelect(['departamento_id', 'departamento_nombre'])
            ->selectRaw('
                COUNT(*) as finalizadas,
                AVG(CASE WHEN minutos_resolucion IS NOT NULL THEN CAST(minutos_resolucion AS FLOAT) END) as prom_resolucion_minutos
            ')
            ->groupBy('departamento_id', 'departamento_nombre')
            ->get()
            ->keyBy('departamento_id');

        // Unión de todos los departamentos que aparecieron en cualquiera de las 3 consultas
        $idsDepartamentos = $activasPorDepto->keys()
            ->merge($respuestaPorDepto->keys())
            ->merge($resolucionPorDepto->keys())
            ->unique();

        return $idsDepartamentos->map(function ($id) use ($activasPorDepto, $respuestaPorDepto, $resolucionPorDepto) {
            $a = $activasPorDepto->get($id);
            $r = $respuestaPorDepto->get($id);
            $f = $resolucionPorDepto->get($id);
            $nombre = $a->departamento_nombre ?? $r->departamento_nombre ?? $f->departamento_nombre;

            return [
                'departamento_id' => (int) $id,
                'departamento_nombre' => $nombre,
                'total' => (int) ($r->total ?? 0),
                'activas' => (int) ($a->activas ?? 0),
                'finalizadas' => (int) ($f->finalizadas ?? 0),
                'vencidas' => (int) ($a->vencidas ?? 0),
                'tiempo_respuesta_prom' => self::minutosAHoras($r->prom_respuesta_minutos ?? null),
                'tiempo_respuesta_mediana' => self::minutosAHoras($r->mediana_minutos_respuesta ?? null),
                'tiempo_resolucion_prom' => self::minutosAHoras($f->prom_resolucion_minutos ?? null),
                'tiempo_resolucion_mediana' => self::minutosAHoras($f->mediana_minutos_resolucion ?? null),
                'cumplimiento_sla_pct' => self::porcentaje($r->cumplieron_sla ?? 0, $r->asignadas ?? 0),
                'sin_datos' => (int) ($r->sin_datos ?? 0),
            ];
        })
            ->sortBy('departamento_nombre')
            ->values()
            ->all();
    }

    // =====================================================================
    // POR TÉCNICO DE MANTENIMIENTO (§6 GET /api/reportes/mantenimiento)
    // =====================================================================

    /**
     * Una fila por técnico de mantenimiento (ordenes_trabajo.usuario_mantenimiento_id).
     * Solo incluye OTs con técnico asignado.
     *
     * @return list<array{
     *   tecnico_id:int, tecnico_nombre:string,
     *   asignadas:int, finalizadas:int, activas:int,
     *   tiempo_resolucion_prom:?float, cumplimiento_sla_pct:?float
     * }>
     */
    public static function porTecnicoMantenimiento($query, $fechaInicio, $fechaFin): array
    {
        [$inicio, $fin] = self::normalizarRango($fechaInicio, $fechaFin);

        $conJoinTecnico = fn () => (clone $query)
            ->whereNotNull('ordenes_trabajo.usuario_mantenimiento_id')
            ->join('users as ot_tecnico', 'ot_tecnico.id', '=', 'ordenes_trabajo.usuario_mantenimiento_id');

        // --- activas: snapshot actual, sin filtro de fechas ---
        $activasPorTecnico = $conJoinTecnico()
            ->where('ordenes_trabajo.estado', '!=', 'finalizada')
            ->select(['ot_tecnico.id as tecnico_id', 'ot_tecnico.name as tecnico_nombre'])
            ->selectRaw('COUNT(*) as activas')
            ->groupBy('ot_tecnico.id', 'ot_tecnico.name')
            ->get()
            ->keyBy('tecnico_id');

        // --- asignadas / cumplimiento SLA: OTs asignadas a este técnico dentro del rango (fecha_asignacion) ---
        $asignadasBase = $conJoinTecnico()
            ->whereBetween('ordenes_trabajo.fecha_asignacion', [$inicio, $fin])
            ->select(['ot_tecnico.id as tecnico_id', 'ot_tecnico.name as tecnico_nombre'])
            ->selectRaw('ordenes_trabajo.fecha_asignacion')
            ->selectRaw(self::vencimientoSql() . ' as vencimiento');

        $asignadasPorTecnico = DB::query()->fromSub($asignadasBase, 'b')
            ->select(['tecnico_id', 'tecnico_nombre'])
            ->selectRaw('
                COUNT(*) as asignadas,
                SUM(CASE WHEN fecha_asignacion <= vencimiento THEN 1 ELSE 0 END) as cumplieron_sla
            ')
            ->groupBy('tecnico_id', 'tecnico_nombre')
            ->get()
            ->keyBy('tecnico_id');

        // --- finalizadas / tiempo de resolución: scope = finalizadas en el rango (por fecha_finalizacion) ---
        $resolucionBase = $conJoinTecnico()
            ->where('ordenes_trabajo.estado', 'finalizada')
            ->whereBetween('ordenes_trabajo.fecha_finalizacion', [$inicio, $fin])
            ->select(['ot_tecnico.id as tecnico_id', 'ot_tecnico.name as tecnico_nombre'])
            ->selectRaw('DATEDIFF(MINUTE, ordenes_trabajo.fecha_asignacion, ordenes_trabajo.fecha_finalizacion) as minutos_resolucion');

        $resolucionPorTecnico = DB::query()->fromSub($resolucionBase, 'b')
            ->select(['tecnico_id', 'tecnico_nombre'])
            ->selectRaw('
                COUNT(*) as finalizadas,
                AVG(CASE WHEN minutos_resolucion IS NOT NULL THEN CAST(minutos_resolucion AS FLOAT) END) as prom_resolucion_minutos
            ')
            ->groupBy('tecnico_id', 'tecnico_nombre')
            ->get()
            ->keyBy('tecnico_id');

        $idsTecnicos = $activasPorTecnico->keys()
            ->merge($asignadasPorTecnico->keys())
            ->merge($resolucionPorTecnico->keys())
            ->unique();

        return $idsTecnicos->map(function ($id) use ($activasPorTecnico, $asignadasPorTecnico, $resolucionPorTecnico) {
            $a = $activasPorTecnico->get($id);
            $asig = $asignadasPorTecnico->get($id);
            $f = $resolucionPorTecnico->get($id);
            $nombre = $a->tecnico_nombre ?? $asig->tecnico_nombre ?? $f->tecnico_nombre;

            return [
                'tecnico_id' => (int) $id,
                'tecnico_nombre' => $nombre,
                'asignadas' => (int) ($asig->asignadas ?? 0),
                'finalizadas' => (int) ($f->finalizadas ?? 0),
                'activas' => (int) ($a->activas ?? 0),
                'tiempo_resolucion_prom' => self::minutosAHoras($f->prom_resolucion_minutos ?? null),
                'cumplimiento_sla_pct' => self::porcentaje($asig->cumplieron_sla ?? 0, $asig->asignadas ?? 0),
            ];
        })
            ->sortBy('tecnico_nombre')
            ->values()
            ->all();
    }

    // =====================================================================
    // TENDENCIA (§6 GET /api/reportes/tendencia)
    // =====================================================================

    /**
     * Serie temporal por semana o mes: creadas vs finalizadas vs tiempo de
     * respuesta promedio. "creadas" se agrupa por created_at, "finalizadas"
     * por fecha_finalizacion (cada métrica cae en el período en que ocurrió
     * su propio evento, no necesariamente el mismo período que la otra).
     *
     * @param string $agrupar 'semana'|'mes'
     * @return list<array{periodo:string, creadas:int, finalizadas:int, tiempo_respuesta_prom:?float}>
     */
    public static function tendencia($query, string $agrupar, $fechaInicio, $fechaFin): array
    {
        if (!in_array($agrupar, ['semana', 'mes'], true)) {
            throw new \InvalidArgumentException("agrupar_por debe ser 'semana' o 'mes', recibido: {$agrupar}");
        }

        [$inicio, $fin] = self::normalizarRango($fechaInicio, $fechaFin);

        $periodoCreadas = self::periodoSql('ordenes_trabajo.created_at', $agrupar);
        $periodoFinalizadas = self::periodoSql('ordenes_trabajo.fecha_finalizacion', $agrupar);

        $creadas = (clone $query)
            ->whereBetween('ordenes_trabajo.created_at', [$inicio, $fin])
            ->selectRaw("{$periodoCreadas} as periodo")
            ->selectRaw('
                COUNT(*) as creadas,
                AVG(CASE WHEN ordenes_trabajo.fecha_asignacion IS NOT NULL
                    THEN CAST(DATEDIFF(MINUTE, ' . self::baseSlaSql() . ', ordenes_trabajo.fecha_asignacion) AS FLOAT)
                    END) as prom_respuesta_minutos
            ')
            ->groupByRaw($periodoCreadas)
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->periodo)->toDateString());

        $finalizadas = (clone $query)
            ->where('ordenes_trabajo.estado', 'finalizada')
            ->whereBetween('ordenes_trabajo.fecha_finalizacion', [$inicio, $fin])
            ->selectRaw("{$periodoFinalizadas} as periodo")
            ->selectRaw('COUNT(*) as finalizadas')
            ->groupByRaw($periodoFinalizadas)
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->periodo)->toDateString());

        $periodos = $creadas->keys()->merge($finalizadas->keys())->unique()->sort()->values();

        return $periodos->map(function ($periodo) use ($creadas, $finalizadas) {
            $c = $creadas->get($periodo);
            $f = $finalizadas->get($periodo);

            return [
                'periodo' => $periodo,
                'creadas' => (int) ($c->creadas ?? 0),
                'finalizadas' => (int) ($f->finalizadas ?? 0),
                'tiempo_respuesta_prom' => self::minutosAHoras($c->prom_respuesta_minutos ?? null),
            ];
        })->values()->all();
    }

    // =====================================================================
    // Helpers privados
    // =====================================================================

    /**
     * Conteo simple agrupado por una columna (estado o prioridad), scope =
     * creadas en el rango. Devuelve un mapa columna => conteo, completando
     * con 0 los valores del catálogo que no tuvieron ninguna fila.
     *
     * @return array<string,int>
     */
    private static function conteoPorColumna($query, string $columna, string $inicio, string $fin, array $valoresPosibles): array
    {
        $conteos = (clone $query)
            ->whereBetween('ordenes_trabajo.created_at', [$inicio, $fin])
            ->select("ordenes_trabajo.{$columna}")
            ->selectRaw('COUNT(*) as total')
            ->groupBy("ordenes_trabajo.{$columna}")
            ->pluck('total', $columna);

        $resultado = [];
        foreach ($valoresPosibles as $valor) {
            $resultado[$valor] = (int) ($conteos[$valor] ?? 0);
        }

        return $resultado;
    }

    /**
     * activas (estado != finalizada, sin filtro de fechas) y vencidas
     * (activas cuyo SLA ya venció y siguen sin asignar).
     */
    private static function agregarActivasVencidas($query): object
    {
        return (clone $query)
            ->where('ordenes_trabajo.estado', '!=', 'finalizada')
            ->selectRaw('
                COUNT(*) as activas,
                SUM(CASE WHEN ordenes_trabajo.fecha_asignacion IS NULL AND ' . self::vencimientoSql() . ' < ' . self::ahoraSql() . ' THEN 1 ELSE 0 END) as vencidas
            ')
            ->first();
    }

    /**
     * total / sin_datos / asignadas / cumplieron_sla / prom_respuesta_minutos /
     * mediana_respuesta_minutos, scope = creadas en el rango.
     * tiempo_respuesta = fecha_aprobacion (fallback created_at) -> fecha_asignacion.
     */
    private static function agregarTiempoRespuesta($query, string $inicio, string $fin): object
    {
        $base = (clone $query)
            ->whereBetween('ordenes_trabajo.created_at', [$inicio, $fin])
            ->selectRaw('ordenes_trabajo.estado, ordenes_trabajo.fecha_asignacion')
            ->selectRaw('DATEDIFF(MINUTE, ' . self::baseSlaSql() . ', ordenes_trabajo.fecha_asignacion) as minutos_respuesta')
            ->selectRaw(self::vencimientoSql() . ' as vencimiento');

        return self::conMedianaParticionada($base, 'minutos_respuesta')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'finalizada' AND fecha_asignacion IS NULL THEN 1 ELSE 0 END) as sin_datos,
                SUM(CASE WHEN fecha_asignacion IS NOT NULL THEN 1 ELSE 0 END) as asignadas,
                SUM(CASE WHEN fecha_asignacion IS NOT NULL AND fecha_asignacion <= vencimiento THEN 1 ELSE 0 END) as cumplieron_sla,
                AVG(CASE WHEN fecha_asignacion IS NOT NULL THEN CAST(minutos_respuesta AS FLOAT) END) as prom_respuesta_minutos
            ")
            ->first();
    }

    /**
     * finalizadas / prom_resolucion_minutos / mediana_resolucion_minutos / prom_total_minutos,
     * scope = finalizadas en el rango (por fecha_finalizacion).
     * tiempo_resolucion = fecha_asignacion -> fecha_finalizacion. tiempo_total = created_at -> fecha_finalizacion.
     */
    private static function agregarTiempoResolucion($query, string $inicio, string $fin): object
    {
        $base = (clone $query)
            ->where('ordenes_trabajo.estado', 'finalizada')
            ->whereBetween('ordenes_trabajo.fecha_finalizacion', [$inicio, $fin])
            ->selectRaw('DATEDIFF(MINUTE, ordenes_trabajo.fecha_asignacion, ordenes_trabajo.fecha_finalizacion) as minutos_resolucion')
            ->selectRaw('DATEDIFF(MINUTE, ordenes_trabajo.created_at, ordenes_trabajo.fecha_finalizacion) as minutos_total');

        return self::conMedianaParticionada($base, 'minutos_resolucion')
            ->selectRaw('
                COUNT(*) as finalizadas,
                AVG(CASE WHEN minutos_resolucion IS NOT NULL THEN CAST(minutos_resolucion AS FLOAT) END) as prom_resolucion_minutos,
                AVG(CAST(minutos_total AS FLOAT)) as prom_total_minutos
            ')
            ->first();
    }

    /**
     * Envuelve $subquery en dos niveles: el primero agrega una columna de
     * ventana "mediana_<col>" con PERCENTILE_CONT(0.5) OVER (PARTITION BY ...)
     * (PERCENTILE_CONT ignora NULLs, por eso no hace falta filtrarlos antes);
     * el segundo nivel queda listo para que el caller agregue sus propios
     * selectRaw()/groupBy() y cierre la consulta con ->first()/->get().
     *
     * SQL Server no permite reusar un alias del SELECT en el GROUP BY: por
     * eso el particionado se hace en un derived table intermedio y el
     * agregado final vuelve a groupear por las columnas "planas" que ya
     * trae ese derived table (no son expresiones calculadas en ese nivel).
     */
    private static function conMedianaParticionada($subquery, string $columnaMinutos, array $particion = []): QueryBuilder
    {
        $particionSql = empty($particion) ? '' : ('PARTITION BY ' . implode(', ', $particion));
        $aliasMediana = "mediana_{$columnaMinutos}";

        $conVentana = DB::query()->fromSub($subquery, 'b')
            ->select('b.*')
            ->selectRaw("PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$columnaMinutos}) OVER ({$particionSql}) as {$aliasMediana}");

        // El nivel final expone la mediana ya calculada como una columna más;
        // el caller la agrega con MAX() porque todas las filas de una misma
        // partición comparten el mismo valor de ventana.
        return DB::query()->fromSub($conVentana, 'w')
            ->selectRaw("MAX({$aliasMediana}) as {$aliasMediana}");
    }

    /**
     * CASE SQL que calcula el vencimiento de SLA de una fila:
     * fecha_aprobacion (fallback created_at) + sla_horas[prioridad].
     * Los valores de horas se leen de config('ot.sla_horas'); vienen de un
     * archivo de configuración (no de input de usuario), por eso se
     * interpolan directo en el SQL sin binding.
     *
     * Público (y no privado) a propósito: OrdenTrabajoController::index()
     * lo reutiliza para el filtro "solo_vencidas", así la definición de
     * vencimiento vive en un solo lugar (no se duplica la lógica del SLA).
     */
    /**
     * "Ahora" como literal SQL, tomado del reloj de PHP en la zona de la app.
     *
     * NO se usa SYSDATETIME(): esa función devuelve la hora local del host de
     * SQL Server, que puede estar en UTC (habitual en contenedores). Si el motor
     * y PHP no coinciden, la misma OT queda vencida en el reporte y no vencida
     * en el listado (que evalúa con Carbon), y para prioridad 'critica' el
     * desfasaje llega a superar el propio SLA de 2 h.
     */
    public static function ahoraSql(): string
    {
        return "'" . Carbon::now('America/Argentina/Buenos_Aires')->format('Y-m-d H:i:s.v') . "'";
    }

    /**
     * Fecha desde la que corre el reloj de primera respuesta: la aprobación,
     * o la creación si no hubo aprobación.
     *
     * La aprobación solo cuenta si ocurrió ANTES de la asignación. Cuando una OT
     * pasa de 'creada' directo a 'asignada', updateEstado() estampa fecha_aprobacion
     * y fecha_asignacion en la misma request: tomarla como base daría un tiempo de
     * respuesta de 0 minutos y un SLA cumplido siempre, ensuciando el promedio.
     * En ese caso el reloj arranca en created_at, que es cuando el pedido entró.
     */
    public static function baseSlaSql(string $tabla = 'ordenes_trabajo'): string
    {
        return "COALESCE(
            CASE WHEN {$tabla}.fecha_aprobacion IS NOT NULL
                  AND ({$tabla}.fecha_asignacion IS NULL OR {$tabla}.fecha_aprobacion < {$tabla}.fecha_asignacion)
                 THEN {$tabla}.fecha_aprobacion END,
            {$tabla}.created_at)";
    }

    public static function vencimientoSql(string $tabla = 'ordenes_trabajo'): string
    {
        $slaHoras = config('ot.sla_horas', []);

        $whenClauses = collect($slaHoras)
            ->map(function ($horas, $prioridad) {
                $prioridad = addslashes((string) $prioridad);
                $horas = (int) $horas;

                return "WHEN '{$prioridad}' THEN {$horas}";
            })
            ->implode(' ');

        // ELSE 48 (SLA de 'media') como resguardo ante un valor de prioridad inesperado
        return 'DATEADD(HOUR, CASE ' . $tabla . '.prioridad ' . $whenClauses . ' ELSE 48 END, ' . self::baseSlaSql($tabla) . ')';
    }

    /**
     * Expresión SQL que trunca una fecha al inicio de su semana (lunes,
     * usando el truco DATEDIFF/DATEADD con base 0 = 1900-01-01, que es
     * independiente de SET DATEFIRST) o al día 1 de su mes.
     */
    private static function periodoSql(string $columna, string $agrupar): string
    {
        return $agrupar === 'mes'
            ? "DATEFROMPARTS(YEAR({$columna}), MONTH({$columna}), 1)"
            : "DATEADD(WEEK, DATEDIFF(WEEK, 0, {$columna}), 0)";
    }

    /**
     * Normaliza fecha_inicio/fecha_fin (Carbon o string) a inicio y fin de
     * día en formato datetime, listos para whereBetween().
     *
     * @return array{0:string,1:string}
     */
    private static function normalizarRango($fechaInicio, $fechaFin): array
    {
        $inicio = Carbon::parse($fechaInicio)->startOfDay();
        $fin = Carbon::parse($fechaFin)->endOfDay();

        return [$inicio->toDateTimeString(), $fin->toDateTimeString()];
    }

    /**
     * Redondea minutos a horas con 1 decimal. Devuelve null si el valor es null
     * (evita que un 0.0 "real" se confunda con "sin datos" en el front).
     */
    private static function minutosAHoras($minutos): ?float
    {
        return $minutos === null ? null : round(((float) $minutos) / 60, 1);
    }

    /**
     * Porcentaje redondeado a 1 decimal; null si el denominador es 0 (no hay
     * base para calcular el %, en vez de devolver un engañoso 0%).
     */
    private static function porcentaje($numerador, $denominador): ?float
    {
        $denominador = (int) $denominador;

        return $denominador > 0 ? round(100 * ((int) $numerador) / $denominador, 1) : null;
    }
}
