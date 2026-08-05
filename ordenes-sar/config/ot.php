<?php

use App\Support\PrioridadOT;

return [

    /*
    |--------------------------------------------------------------------------
    | SLA de primera respuesta (§2 de la spec)
    |--------------------------------------------------------------------------
    |
    | Horas objetivo entre la creación/aprobación de la OT y su primera
    | asignación (fecha_asignacion). Se usa tanto para el semáforo de una OT
    | individual como para el cálculo de "vencidas" y "cumplimiento_sla_pct"
    | en los reportes (App\Support\ReporteQueries).
    |
    | NO hardcodear estos números en controllers ni en React: el front los
    | recibe del backend (catálogo servido en /api/ot/catalogos o en el
    | payload de /api/reportes/resumen).
    |
    */

    'sla_horas' => [
        PrioridadOT::CRITICA => 2,
        PrioridadOT::ALTA => 8,
        PrioridadOT::MEDIA => 48,
        PrioridadOT::BAJA => 168,
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogos para el front (evita duplicar la tabla de mapeo en JS)
    |--------------------------------------------------------------------------
    */

    // categoria => label en español
    'categorias' => PrioridadOT::CATEGORIA_LABELS,

    // prioridad => label en español
    'prioridades' => PrioridadOT::PRIORIDAD_LABELS,

    // prioridad => ['tag' => color AntD, 'hex' => color hexadecimal]
    'prioridad_colores' => PrioridadOT::PRIORIDAD_COLORES,

    // categoria => prioridad automática (para mostrar el preview en vivo del modal de creación)
    'categoria_prioridad' => PrioridadOT::CATEGORIA_PRIORIDAD,

    // prioridad => orden numérico canónico (1 = más urgente)
    'prioridad_orden' => PrioridadOT::PRIORIDAD_ORDEN,

];
