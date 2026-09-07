<?php


use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartamentoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrdenTrabajoController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DescripcionController;
use App\Http\Controllers\NotificacionesController;
use App\Http\Controllers\MensajeController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\SolicitudHheeController;
use App\Http\Controllers\HheeIntegracionController;

/*
|---------------------------------------------------------------------------
| API Routes
|---------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::group(['middleware' => ['cors', 'json.response']], function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    /* Route::get('login', [AuthController::class, 'login']); */
});

Route::group(['middleware' => ['auth:api', 'cors', 'json.response']], function () {
    Route::get('/usuarios-mantenimiento', [UserController::class, 'getUsuariosMantenimiento']);
    Route::get('/user', [UserController::class, 'getAuthenticatedUser']);

    Route::get('/departamentos', [DepartamentoController::class, 'index']);


    Route::get('/descripciones', [DescripcionController::class, 'index']);
    Route::get('/archivos/{archivo}', [DescripcionController::class, 'showArchivo'])->where('archivo', '.*');
    Route::get('/ordenes-trabajo/{id}/foto-finalizada', [OrdenTrabajoController::class, 'getFotoFinalizada']);
    Route::get('/ordenes-trabajo', [OrdenTrabajoController::class, 'index']);

    Route::get('/ordenes-trabajo/{id}', [OrdenTrabajoController::class, 'show']);
    Route::get('/notificaciones', [NotificacionesController::class, 'index']);
    // marcarTodasLeidas escribe, pero SOLO sobre la bandeja del propio usuario
    // (where usuario_creador_id = auth id): no altera OTs ni notifica a nadie, así
    // que queda fuera del bloqueo (si no, SyH tendría la campanita trabada).
    Route::put('/notificaciones/marcar-todas-leidas', [NotificacionesController::class, 'marcarTodasLeidas']);
    Route::get('/usuarios', [UserController::class, 'index']);
    Route::get('/ordenes-trabajo/{id}/descripciones', [DescripcionController::class, 'getDescripcionesByOrdenId']);

    Route::get('/ordenes-trabajo/{id}/mensajes', [MensajeController::class, 'index']);

    // Crear una OT nueva está permitido para cualquier usuario/departamento
    // (incluido SyH): no hay una OT objetivo que pueda ser "ajena" todavía,
    // así que queda fuera del grupo de abajo (que necesita una OT existente
    // para evaluar el alcance).
    Route::post('/ordenes-trabajo', [OrdenTrabajoController::class, 'store']);

    // -------------------------------------------------------------------
    // Rutas de ESCRITURA sobre una OT existente (o sus mensajes/descripciones):
    // quedan detrás de 'bloquear.escritura.orden.ajena' (ver
    // App\Http\Middleware\BloquearEscrituraOrdenAjena), que resuelve la OT
    // objetivo de la request y bloquea solo si el usuario no puede escribir
    // en ESA orden puntual (hoy: Seguridad e Higiene sobre una OT ajena que
    // ve por estar marcada de seguridad). Para el resto de los usuarios no
    // cambia nada. Cualquier endpoint de escritura nuevo sobre una OT debe
    // agregarse ACÁ para quedar cubierto por defecto.
    // -------------------------------------------------------------------
    Route::middleware(['bloquear.escritura.orden.ajena'])->group(function () {
        Route::delete('/ordenes-trabajo/{id}', [OrdenTrabajoController::class, 'destroy']);
        Route::post('/ordenes-trabajo/{ordenId}/agregar-archivos', [OrdenTrabajoController::class, 'agregarArchivos']);
        Route::put('/ordenes-trabajo/{id}/finalizar', [OrdenTrabajoController::class, 'finalizarOrdenTrabajo']);
        Route::put('/ordenes-trabajo/{id}/finalizar2', [OrdenTrabajoController::class, 'finalizarOrdenTrabajo2']);

        Route::put('/ordenes-trabajo/{id}/estado', [OrdenTrabajoController::class, 'updateEstado']);
        Route::post('/ordenes-trabajo/{id}/descripciones', [DescripcionController::class, 'store']);
        Route::post('/mensajes', [MensajeController::class, 'store']);
        Route::put('/ordenes-trabajo/{id}/mensajes/visto', [MensajeController::class, 'marcarVisto']);
        Route::put('/ordenes-trabajo/{id}/aprobar', [OrdenTrabajoController::class, 'aprobarOrden']);

        // Override manual de prioridad (§1/§6 de SPEC-prioridad-reportes.md)
        Route::put('/ordenes-trabajo/{id}/prioridad', [OrdenTrabajoController::class, 'updatePrioridad']);

        // Crea una notificación dirigida a OTRO usuario sobre una OT puntual
        // (orden_trabajo_id en el body): queda sujeta al mismo chequeo por-OT.
        Route::post('/notificaciones', [NotificacionesController::class, 'enviarNotificacion']);
    });

    // Catálogos de categorías/prioridades/SLA para el front (§7)
    Route::get('/ot/catalogos', [OrdenTrabajoController::class, 'catalogos']);

    // Reportes/KPIs (§6)
    Route::prefix('reportes')->group(function () {
        Route::get('/resumen', [ReporteController::class, 'resumen']);
        Route::get('/departamentos', [ReporteController::class, 'departamentos']);
        Route::get('/mantenimiento', [ReporteController::class, 'mantenimiento']);
        Route::get('/tendencia', [ReporteController::class, 'tendencia']);
    });

    // -------------------------------------------------------------------
    // Módulo HHEE (horas extras): dominio propio, sin relación con
    // ordenes_trabajo, así que queda FUERA de 'bloquear.escritura.orden.ajena'
    // (ese middleware solo resuelve alcance sobre una OT). La autorización de
    // escritura del módulo la resuelven App\Support\AlcanceHhee/HheeFlujo.
    // -------------------------------------------------------------------
    Route::prefix('hhee')->group(function () {
        Route::get('/catalogos', [SolicitudHheeController::class, 'catalogos']);
        Route::get('/pendientes', [SolicitudHheeController::class, 'pendientes']);

        Route::get('/solicitudes', [SolicitudHheeController::class, 'index']);
        Route::post('/solicitudes', [SolicitudHheeController::class, 'store']);
        Route::get('/solicitudes/{id}', [SolicitudHheeController::class, 'show']);
        Route::put('/solicitudes/{id}', [SolicitudHheeController::class, 'update']);
        Route::delete('/solicitudes/{id}', [SolicitudHheeController::class, 'destroy']);

        Route::post('/solicitudes/{id}/enviar', [SolicitudHheeController::class, 'enviar']);
        Route::post('/solicitudes/{id}/aprobar', [SolicitudHheeController::class, 'aprobar']);
        Route::post('/solicitudes/{id}/rechazar', [SolicitudHheeController::class, 'rechazar']);
        Route::post('/solicitudes/{id}/anular', [SolicitudHheeController::class, 'anular']);
        Route::post('/solicitudes/{id}/horas-reales', [SolicitudHheeController::class, 'horasReales']);
    });
});

// -----------------------------------------------------------------------
// Integración servidor-a-servidor de HHEE con APP-RRHH (Laravel aparte,
// :8587): FUERA del grupo 'auth:api' a propósito -- APP-RRHH no tiene un
// usuario/token de este sistema, se autentica con un secreto compartido
// (header X-Integracion-Key, ver App\Http\Middleware\VerificarIntegracionHhee
// / config('hhee.integracion_key')). APP-RRHH consulta las HHEE leyendo la
// base ordenes_sar directamente (solo lectura); estos endpoints son
// exclusivamente para GESTIONAR (aprobar/rechazar) la firma final.
// -----------------------------------------------------------------------
Route::prefix('hhee/integracion')
    ->middleware(['hhee.integracion', 'cors', 'json.response'])
    ->group(function () {
        Route::post('/solicitudes/{id}/aprobar', [HheeIntegracionController::class, 'aprobar']);
        Route::post('/solicitudes/{id}/rechazar', [HheeIntegracionController::class, 'rechazar']);
    });
