<?php

use App\Support\OpenIssueEstados;

return [

    // Fuente de verdad: App\Support\OpenIssueEstados. NO duplicar el mapeo acá.
    'estados_labels' => OpenIssueEstados::ESTADOS_LABELS,
    'prioridades_labels' => OpenIssueEstados::PRIORIDADES_LABELS,
    'tipos_actualizacion_labels' => OpenIssueEstados::TIPOS_ACTUALIZACION_LABELS,

    // Prioridad asignada si el alta no manda ninguna.
    'prioridad_default' => 'media',

    // Roles (users.rol) con alcance de LECTURA global: ven todos los issues y la tab "Todos".
    // 'admin' no es insertable hoy en el enum de users.rol: se deja por el mismo motivo que
    // App\Support\AlcanceOrdenes (compatibilidad legacy).
    'roles_ven_todo' => ['gerente', 'admin'],

    // Tope defensivo de filas de oi_involucrados insertadas en una sola operación
    // (protege contra expandir por error un departamento gigante).
    'max_involucrados_por_lote' => 200,

    // Paginación del listado.
    'per_page_default' => 25,
    'per_page_max' => 100,

    // Adjuntos: mismas reglas que DescripcionController::store() (App\Support\ArchivoOrden).
    'adjunto' => [
        'max_kb' => 5120,
        'mimes' => ['jpeg', 'png', 'jpg', 'gif', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
    ],

];
