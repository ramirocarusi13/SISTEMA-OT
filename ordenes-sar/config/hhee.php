<?php

use App\Support\HheeEstados;

return [

    /*
    |--------------------------------------------------------------------------
    | Roles de aprobación por nivel
    |--------------------------------------------------------------------------
    |
    | Matriz nivel -> roles que pueden firmar ese nivel (hhee_roles_aprobacion.rol).
    | AMBOS niveles respetan departamento_id de la fila en hhee_roles_aprobacion:
    | NULL = alcance global, un id concreto = solo ese departamento. Así pueden
    | convivir finales "de área" (ej. una última firma solo para ciertos
    | departamentos) con finales globales como gerencia general.
    |
    | El módulo asume EXACTAMENTE 2 niveles de aprobación (ver
    | hhee_aprobaciones.nivel, tinyint 1|2, y hhee_solicitudes.estado:
    | pendiente_nivel1 / pendiente_final): agregar un tercer nivel acá NO
    | alcanza, hay que revisar también App\Support\HheeFlujo::nivelPendiente()
    | y la migración de hhee_aprobaciones (UNIQUE por solicitud_id+nivel).
    |
    | NO hardcodear estos roles en controllers: usar App\Support\HheeAprobadores
    | (única fuente de verdad de autorización de firmas).
    |
    */

    'niveles' => [
        1 => ['jefe', 'gerente_area'],
        2 => ['gerencia_general', 'rrhh', 'presidencia'],
    ],

    // Rol "comodín" que puede firmar CUALQUIER nivel pendiente (ver
    // App\Support\HheeAprobadores). La firma queda marcada es_contingencia=1,
    // salvo que el usuario también tenga el rol legítimo de ese nivel (en ese
    // caso la firma es normal, con rol_aprobador = el rol legítimo).
    'rol_contingencia' => 'contingencia',

    // rol (hhee_roles_aprobacion.rol) -> label en español, para catálogos del
    // front (ver SolicitudHheeController::catalogos()).
    'roles_labels' => [
        'jefe' => 'Jefe',
        'gerente_area' => 'Gerente de área',
        'gerencia_general' => 'Gerencia general',
        'rrhh' => 'RRHH',
        'presidencia' => 'Presidencia',
        'contingencia' => 'Contingencia',
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo de estados (fuente de verdad: App\Support\HheeEstados)
    |--------------------------------------------------------------------------
    |
    | NO duplicar este mapeo acá: se referencia la constante de la clase, igual
    | que config('ot.php') hace con App\Support\PrioridadOT.
    |
    */

    'estados_labels' => HheeEstados::ESTADOS_LABELS,

    /*
    |--------------------------------------------------------------------------
    | Reglas de horas (formulario FO-008-RRH)
    |--------------------------------------------------------------------------
    */

    // Tope de horas totales (desglose teórico, suma de los 4 tipos) por
    // empleado en una solicitud. Ver App\Support\HheeFlujo::validarDesglose().
    'max_horas_por_empleado' => env('HHEE_MAX_HORAS', 12),

    // Si está en false (default), un aprobador NO puede firmar (ni por
    // contingencia) una solicitud de la que él mismo es el solicitante.
    'permitir_autoaprobacion' => env('HHEE_AUTOAPROBACION', false),

    // Rol (hhee_roles_aprobacion.rol) al que se notifica cuando una solicitud
    // se cierra (carga de horas reales). Ver App\Support\HheeNotificador::notificarCerrada().
    // NO hardcodear 'rrhh' en HheeNotificador: se referencia esta config.
    'rol_notificacion_cierre' => 'rrhh',

    // Tipos de hora del desglose (columnas hs_teoricas_*/hs_reales_* de
    // hhee_solicitud_detalles) -> label en español.
    'tipos_hora' => [
        '50' => '50%',
        '100' => '100%',
        '50n' => '50% nocturno',
        '100n' => '100% nocturno',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sector (hhee_solicitudes.sector)
    |--------------------------------------------------------------------------
    |
    | Opciones fijas del campo "Sector" del formulario FO-008-RRH. Reemplaza
    | al selector libre de departamento_id: el usuario ya NO elige
    | departamento (sale siempre del solicitante logueado, ver
    | App\Support\HheeFlujo::crear()/actualizar()), pero sí elige un sector
    | descriptivo de estas 4 opciones fijas.
    |
    */

    'sectores' => ['Corte', 'Costura', 'Mantenimiento', 'PC', 'Staff'],

    /*
    |--------------------------------------------------------------------------
    | Integración servidor-a-servidor con APP-RRHH
    |--------------------------------------------------------------------------
    |
    | APP-RRHH (Laravel aparte, :8587) consulta las HHEE leyendo la base
    | ordenes_sar directamente (solo lectura), pero para GESTIONAR (aprobar/
    | rechazar la firma final) llama a esta API server-to-server, autenticada
    | por este key compartido (header X-Integracion-Key, ver
    | App\Http\Middleware\VerificarIntegracionHhee), NO por auth:api/Passport.
    |
    | NUNCA hardcodear el valor acá: sale de env('HHEE_INTEGRACION_KEY'), un
    | secreto random de 32+ caracteres generado a mano y cargado en el .env de
    | cada entorno (dev/prod tienen valores DISTINTOS). Si no está configurado
    | (null), el middleware responde 503 en vez de dejar pasar por error.
    |
    */

    'integracion_key' => env('HHEE_INTEGRACION_KEY'),

];
