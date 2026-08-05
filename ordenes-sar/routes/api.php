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

    // -------------------------------------------------------------------
    // Rutas de ESCRITURA sobre OTs/mensajes/descripciones: el departamento
    // Seguridad e Higiene (SyH) tiene acceso de solo lectura, así que todas
    // quedan detrás de 'bloquear.escritura.seguridad' (ver
    // App\Http\Middleware\BloquearEscrituraSeguridad). Cualquier endpoint de
    // escritura nuevo debe agregarse ACÁ para quedar cubierto por defecto.
    // -------------------------------------------------------------------
    Route::middleware(['bloquear.escritura.seguridad'])->group(function () {
        Route::delete('/ordenes-trabajo/{id}', [OrdenTrabajoController::class, 'destroy']);
        Route::post('/ordenes-trabajo/{ordenId}/agregar-archivos', [OrdenTrabajoController::class, 'agregarArchivos']);
        Route::put('/ordenes-trabajo/{id}/finalizar', [OrdenTrabajoController::class, 'finalizarOrdenTrabajo']);
        Route::put('/ordenes-trabajo/{id}/finalizar2', [OrdenTrabajoController::class, 'finalizarOrdenTrabajo2']);

        // NOTA: store() queda bloqueado para SyH bajo el supuesto acordado de
        // que, por ahora, SyH no crea OTs propias (solo consulta las que le
        // competen). Si eso cambia, sacar esta ruta de este grupo (o sumar
        // una excepción puntual) para volver a permitir la creación.
        Route::post('/ordenes-trabajo', [OrdenTrabajoController::class, 'store']);

        Route::put('/ordenes-trabajo/{id}/estado', [OrdenTrabajoController::class, 'updateEstado']);
        Route::post('/ordenes-trabajo/{id}/descripciones', [DescripcionController::class, 'store']);
        Route::post('/mensajes', [MensajeController::class, 'store']);
        Route::put('/ordenes-trabajo/{id}/mensajes/visto', [MensajeController::class, 'marcarVisto']);
        Route::put('/ordenes-trabajo/{id}/aprobar', [OrdenTrabajoController::class, 'aprobarOrden']);

        // Override manual de prioridad (§1/§6 de SPEC-prioridad-reportes.md)
        Route::put('/ordenes-trabajo/{id}/prioridad', [OrdenTrabajoController::class, 'updatePrioridad']);

        // Crea una notificación dirigida a OTRO usuario sobre una OT: es escritura
        // hacia afuera, no bandeja propia, así que también queda bloqueada.
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
});
