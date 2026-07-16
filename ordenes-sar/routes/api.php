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


    Route::delete('/ordenes-trabajo/{id}', [OrdenTrabajoController::class, 'destroy']);
	Route::post('/ordenes-trabajo/{ordenId}/agregar-archivos', [OrdenTrabajoController::class, 'agregarArchivos']);
    Route::put('/ordenes-trabajo/{id}/finalizar', [OrdenTrabajoController::class, 'finalizarOrdenTrabajo']);
    Route::put('/ordenes-trabajo/{id}/finalizar2', [OrdenTrabajoController::class, 'finalizarOrdenTrabajo2']);





    Route::get('/ordenes-trabajo/{id}', [OrdenTrabajoController::class, 'show']);
    Route::post('/ordenes-trabajo', [OrdenTrabajoController::class, 'store']);
    Route::put('/ordenes-trabajo/{id}/estado', [OrdenTrabajoController::class, 'updateEstado']);
    Route::get('/notificaciones', [NotificacionesController::class, 'index']);
    Route::put('/notificaciones/marcar-todas-leidas', [NotificacionesController::class, 'marcarTodasLeidas']);
    Route::get('/usuarios', [UserController::class, 'index']);
    Route::get('/ordenes-trabajo/{id}/descripciones', [DescripcionController::class, 'getDescripcionesByOrdenId']);
    Route::post('/notificaciones', [NotificacionesController::class, 'enviarNotificacion']);
    Route::post('/ordenes-trabajo/{id}/descripciones', [DescripcionController::class, 'store']);

    Route::get('/ordenes-trabajo/{id}/mensajes', [MensajeController::class, 'index']);
    Route::post('/mensajes', [MensajeController::class, 'store']);
    Route::put('/ordenes-trabajo/{id}/mensajes/visto', [MensajeController::class, 'marcarVisto']);
    Route::put('/ordenes-trabajo/{id}/aprobar', [OrdenTrabajoController::class, 'aprobarOrden']);
});
