<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage/archivos/{archivo}', function (string $archivo) {
    $archivo = basename($archivo);
    $paths = [
        public_path('storage/archivos/' . $archivo),
        storage_path('app/public/archivos/' . $archivo),
    ];

    foreach ($paths as $path) {
        if (File::exists($path)) {
            return response()->file($path);
        }
    }

    abort(404);
})->where('archivo', '.*');
