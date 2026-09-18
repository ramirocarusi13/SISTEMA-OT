<?php

namespace App\Support;

/**
 * Única fuente de verdad del vocabulario del módulo Open Issues: estados de
 * oi_issues.estado, prioridades de oi_issues.prioridad y tipos de
 * oi_actualizaciones.tipo.
 *
 * Máquina de estados:
 *
 *   abierto <-----> en_progreso
 *      ^                 |
 *      |                 v
 *      +------------ cerrado
 *
 * 'cerrado -> abierto' SOLO se dispara por POST /{id}/reabrir (nunca por
 * POST /{id}/actualizaciones, ver ESTADOS_MANUALES). Esta clase solo modela
 * la forma ABSTRACTA de la máquina de estados (qué transiciones existen);
 * quién puede disparar cada una vive en App\Support\OpenIssueFlujo /
 * App\Support\AlcanceOpenIssues.
 */
class OpenIssueEstados
{
    public const ABIERTO = 'abierto';
    public const EN_PROGRESO = 'en_progreso';
    public const CERRADO = 'cerrado';

    public const ESTADOS_LABELS = [
        self::ABIERTO => ['label' => 'Abierto', 'color' => 'gold'],
        self::EN_PROGRESO => ['label' => 'En progreso', 'color' => 'blue'],
        self::CERRADO => ['label' => 'Cerrado', 'color' => 'green'],
    ];

    // Máquina de estados ABSTRACTA. Quién puede disparar cada transición lo decide OpenIssueFlujo.
    public const TRANSICIONES = [
        self::ABIERTO => [self::EN_PROGRESO, self::CERRADO],
        self::EN_PROGRESO => [self::ABIERTO, self::CERRADO],
        self::CERRADO => [self::ABIERTO],   // solo por POST /{id}/reabrir
    ];

    public const TERMINALES = [self::CERRADO];

    // Estados que POST /{id}/actualizaciones acepta en 'nuevo_estado'. Cerrar y reabrir
    // tienen endpoint propio y NO se pueden pedir por ahí.
    public const ESTADOS_MANUALES = [self::ABIERTO, self::EN_PROGRESO];

    // Mismos colores de tag que App\Support\PrioridadOT (alta=orange, media=blue, baja=default),
    // para que el sistema se vea consistente entre módulos.
    public const PRIORIDADES_LABELS = [
        'baja' => ['label' => 'Baja', 'color' => 'default'],
        'media' => ['label' => 'Media', 'color' => 'blue'],
        'alta' => ['label' => 'Alta', 'color' => 'orange'],
    ];

    public const TIPO_APERTURA = 'apertura';
    public const TIPO_COMENTARIO = 'comentario';
    public const TIPO_CAMBIO_ESTADO = 'cambio_estado';
    public const TIPO_CIERRE = 'cierre';
    public const TIPO_REAPERTURA = 'reapertura';
    public const TIPO_INVOLUCRADO_AGREGADO = 'involucrado_agregado';
    public const TIPO_INVOLUCRADO_QUITADO = 'involucrado_quitado';
    public const TIPO_EDICION = 'edicion';

    public const TIPOS_ACTUALIZACION_LABELS = [
        self::TIPO_APERTURA => 'Apertura',
        self::TIPO_COMENTARIO => 'Comentario',
        self::TIPO_CAMBIO_ESTADO => 'Cambio de estado',
        self::TIPO_CIERRE => 'Cierre',
        self::TIPO_REAPERTURA => 'Reapertura',
        self::TIPO_INVOLUCRADO_AGREGADO => 'Involucrado agregado',
        self::TIPO_INVOLUCRADO_QUITADO => 'Involucrado quitado',
        self::TIPO_EDICION => 'Edición',
    ];

    /**
     * Estados destino alcanzables desde $estado en una única transición.
     *
     * @return string[]
     */
    public static function transicionesPermitidas(string $estado): array
    {
        return self::TRANSICIONES[$estado] ?? [];
    }

    /**
     * True si la transición $de -> $a es válida según la máquina de estados
     * (sin mirar quién la ejecuta: eso lo valida OpenIssueFlujo/el controller).
     */
    public static function puedeTransicionar(string $de, string $a): bool
    {
        return in_array($a, self::transicionesPermitidas($de), true);
    }

    /**
     * True si $estado ya no admite ninguna transición (cerrado).
     */
    public static function esTerminal(string $estado): bool
    {
        return in_array($estado, self::TERMINALES, true);
    }

    /**
     * Lista de los 3 estados válidos (para Rule::in()).
     *
     * @return string[]
     */
    public static function estados(): array
    {
        return array_keys(self::ESTADOS_LABELS);
    }

    /**
     * Lista de las 3 prioridades válidas (para Rule::in()).
     *
     * @return string[]
     */
    public static function prioridades(): array
    {
        return array_keys(self::PRIORIDADES_LABELS);
    }

    /**
     * Catálogo estado -> {value, label, color} para el front (evita duplicar
     * el mapeo de estados en JS).
     */
    public static function catalogo(): array
    {
        return collect(self::ESTADOS_LABELS)->map(fn ($info, $estado) => [
            'value' => $estado,
            'label' => $info['label'],
            'color' => $info['color'],
        ])->values()->all();
    }

    /**
     * Catálogo prioridad -> {value, label, color} para el front.
     */
    public static function catalogoPrioridades(): array
    {
        return collect(self::PRIORIDADES_LABELS)->map(fn ($info, $prioridad) => [
            'value' => $prioridad,
            'label' => $info['label'],
            'color' => $info['color'],
        ])->values()->all();
    }

    /**
     * Label en español de un estado (lo usan los mensajes de campana).
     */
    public static function label(string $estado): string
    {
        return self::ESTADOS_LABELS[$estado]['label'] ?? $estado;
    }
}
