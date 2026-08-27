<?php

namespace App\Support;

/**
 * Constantes y máquina de estados de hhee_solicitudes.estado.
 *
 *   borrador -> pendiente_nivel1 -> pendiente_final -> aprobada -> cerrada
 *                     |                    |
 *                     v                    v
 *                 rechazada            rechazada
 *
 * 'anulada' es alcanzable desde CUALQUIER estado NO terminal (borrador,
 * pendiente_nivel1, pendiente_final, aprobada). Esta clase solo modela la
 * forma ABSTRACTA de la máquina de estados (qué transiciones existen); la
 * autorización de quién puede disparar cada una vive en
 * App\Support\HheeFlujo/App\Support\HheeAprobadores.
 *
 * Terminales (esTerminal()): cerrada, rechazada, anulada.
 */
class HheeEstados
{
    public const BORRADOR = 'borrador';
    public const PENDIENTE_NIVEL1 = 'pendiente_nivel1';
    public const PENDIENTE_FINAL = 'pendiente_final';
    public const APROBADA = 'aprobada';
    public const CERRADA = 'cerrada';
    public const RECHAZADA = 'rechazada';
    public const ANULADA = 'anulada';

    /**
     * Labels + color (tag AntD) por estado, para el front (ver
     * config('hhee.estados_labels') y catalogo()).
     */
    public const ESTADOS_LABELS = [
        self::BORRADOR => ['label' => 'Borrador', 'color' => 'default'],
        self::PENDIENTE_NIVEL1 => ['label' => 'Pendiente nivel 1', 'color' => 'gold'],
        self::PENDIENTE_FINAL => ['label' => 'Pendiente aprobación final', 'color' => 'orange'],
        self::APROBADA => ['label' => 'Aprobada', 'color' => 'green'],
        self::CERRADA => ['label' => 'Cerrada', 'color' => 'blue'],
        self::RECHAZADA => ['label' => 'Rechazada', 'color' => 'red'],
        self::ANULADA => ['label' => 'Anulada', 'color' => 'default'],
    ];

    /**
     * Mapa estado origen -> lista de estados destino alcanzables en una única
     * transición.
     */
    public const TRANSICIONES = [
        self::BORRADOR => [self::PENDIENTE_NIVEL1, self::ANULADA],
        self::PENDIENTE_NIVEL1 => [self::PENDIENTE_FINAL, self::RECHAZADA, self::ANULADA],
        self::PENDIENTE_FINAL => [self::APROBADA, self::RECHAZADA, self::ANULADA],
        self::APROBADA => [self::CERRADA, self::ANULADA],
        self::CERRADA => [],
        self::RECHAZADA => [],
        self::ANULADA => [],
    ];

    public const TERMINALES = [self::CERRADA, self::RECHAZADA, self::ANULADA];

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
     * (sin mirar quién la ejecuta: eso lo valida HheeFlujo/el controller).
     */
    public static function puedeTransicionar(string $de, string $a): bool
    {
        return in_array($a, self::transicionesPermitidas($de), true);
    }

    /**
     * True si $estado ya no admite ninguna transición (cerrada, rechazada,
     * anulada).
     */
    public static function esTerminal(string $estado): bool
    {
        return in_array($estado, self::TERMINALES, true);
    }

    /**
     * Lista de los 7 estados válidos, en el orden del flujo (para Rule::in()
     * y para validar datos legacy).
     *
     * @return string[]
     */
    public static function estados(): array
    {
        return array_keys(self::ESTADOS_LABELS);
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
}
