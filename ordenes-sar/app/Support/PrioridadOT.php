<?php

namespace App\Support;

/**
 * Constantes y reglas de negocio del modelo de prioridad de las OT.
 * Ver SPEC-prioridad-reportes.md §1 (fuente de verdad).
 *
 * No hardcodear estos strings/colores/mapeos en controllers ni en React:
 * el front consume el catálogo servido por el backend (config('ot')).
 */
class PrioridadOT
{
    // ---- Prioridades ----------------------------------------------------
    public const CRITICA = 'critica';
    public const ALTA = 'alta';
    public const MEDIA = 'media';
    public const BAJA = 'baja';

    // ---- Categorías (las elige el creador al crear la OT) ---------------
    public const CAT_SEGURIDAD = 'seguridad';
    public const CAT_PARADA_LINEA = 'parada_linea';
    public const CAT_CALIDAD = 'calidad';
    public const CAT_AVERIA = 'averia';
    public const CAT_MEJORA = 'mejora';

    /**
     * Categoría -> prioridad automática (§1 de la spec).
     */
    public const CATEGORIA_PRIORIDAD = [
        self::CAT_SEGURIDAD => self::CRITICA,
        self::CAT_PARADA_LINEA => self::ALTA,
        self::CAT_CALIDAD => self::ALTA,
        self::CAT_AVERIA => self::MEDIA,
        self::CAT_MEJORA => self::BAJA,
    ];

    /**
     * Orden canónico para ORDER BY (1 = más urgente). Se persiste también en
     * ordenes_trabajo.prioridad_orden para poder ordenar en SQL sin CASE.
     */
    public const PRIORIDAD_ORDEN = [
        self::CRITICA => 1,
        self::ALTA => 2,
        self::MEDIA => 3,
        self::BAJA => 4,
    ];

    /**
     * Labels en español para la UI.
     */
    public const PRIORIDAD_LABELS = [
        self::CRITICA => 'Crítica',
        self::ALTA => 'Alta',
        self::MEDIA => 'Media',
        self::BAJA => 'Baja',
    ];

    public const CATEGORIA_LABELS = [
        self::CAT_SEGURIDAD => 'Seguridad / riesgo',
        self::CAT_PARADA_LINEA => 'Parada de línea',
        self::CAT_CALIDAD => 'Calidad / producto',
        self::CAT_AVERIA => 'Avería sin parada',
        self::CAT_MEJORA => 'Mejora / confort',
    ];

    /**
     * Colores exactos de §1 (tag de AntD + hex) por prioridad.
     */
    public const PRIORIDAD_COLORES = [
        self::CRITICA => ['tag' => 'red', 'hex' => '#cf1322'],
        self::ALTA => ['tag' => 'orange', 'hex' => '#d46b08'],
        self::MEDIA => ['tag' => 'blue', 'hex' => '#0958d9'],
        self::BAJA => ['tag' => 'default', 'hex' => '#8c8c8c'],
    ];

    /**
     * Calcula la prioridad resultante para una categoría, aplicando la regla
     * "seguridad ⇒ crítica" (por categoría 'seguridad' o por el checkbox manual
     * es_seguridad). No aplica overrides manuales de prioridad: eso lo maneja
     * el controller (solo admin/gerente/MTTO pueden overridear, ver §1).
     *
     * @return array{prioridad: string, prioridad_orden: int}
     */
    public static function calcular(string $categoria, bool $esSeguridad): array
    {
        $prioridad = ($esSeguridad || $categoria === self::CAT_SEGURIDAD)
            ? self::CRITICA
            : (self::CATEGORIA_PRIORIDAD[$categoria] ?? self::MEDIA);

        return [
            'prioridad' => $prioridad,
            'prioridad_orden' => self::PRIORIDAD_ORDEN[$prioridad],
        ];
    }

    /**
     * Horas de SLA (primera respuesta) configuradas para una prioridad.
     * Lee de config('ot.sla_horas'); si la prioridad no existe en la config
     * (dato corrupto/legacy), cae al SLA de 'media' como resguardo.
     */
    public static function slaHoras(string $prioridad): int
    {
        $slaHoras = config('ot.sla_horas', []);

        return (int) ($slaHoras[$prioridad] ?? $slaHoras[self::MEDIA] ?? 48);
    }

    /**
     * Lista de categorías válidas, en el orden de la tabla de §1 (útil para
     * Rule::in() en el controller y para el Select del front).
     *
     * @return string[]
     */
    public static function categorias(): array
    {
        return array_keys(self::CATEGORIA_PRIORIDAD);
    }

    /**
     * Lista de prioridades válidas, ordenadas de más a menos urgente.
     *
     * @return string[]
     */
    public static function prioridades(): array
    {
        return array_keys(self::PRIORIDAD_ORDEN);
    }
}
