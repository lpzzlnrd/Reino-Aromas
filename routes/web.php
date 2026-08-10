<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LegalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Raíz → login
|--------------------------------------------------------------------------
*/
Route::get('/', fn() => redirect()->route('login'));

/*
|--------------------------------------------------------------------------
| Páginas legales — PÚBLICAS, sin autenticación
|
| Meta abre estas URLs durante App Review y rechaza la app si devuelven 404 o
| piden login. Van deliberadamente fuera de los grupos 'auth' y 'guest': el
| middleware 'guest' redirigiría a /app a un usuario ya logueado que quiera
| leer la política, que no es lo que queremos.
|
| Estas rutas se cargan en App Dashboard → Configuración → Básica:
|   /privacidad           → Privacy Policy URL
|   /terminos             → Terms of Service URL
|   /eliminacion-de-datos → Data Deletion Instructions URL
|--------------------------------------------------------------------------
*/
Route::controller(LegalController::class)->group(function () {
    Route::get('/privacidad', 'privacidad')->name('legal.privacidad');
    Route::get('/terminos', 'terminos')->name('legal.terminos');
    Route::get('/eliminacion-de-datos', 'eliminacionDatos')->name('legal.eliminacion-datos');
});

/*
|--------------------------------------------------------------------------
| Autenticación — solo para invitados
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| SPA Shell — todas las rutas /app/* las maneja Vue Router.
|
| Una sola ruta catch-all devuelve el Blade shell (app.blade.php) que monta
| el Vue. Vue Router toma control a partir de ahí y resuelve la sub-ruta.
|
| El middleware 'auth' garantiza que sin sesión activa Laravel redirige al
| login de Blade — Vue nunca llega a cargarse para usuarios no autenticados.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:superadmin,administrador'])
    ->prefix('app')
    ->group(function () {
        // Ruta base /app
        Route::get('/', fn() => view('app'))->name('app');

        // Catch-all para sub-rutas del Vue Router (/app/messages, /app/settings/*, etc.)
        // Sin esto, un refresh en /app/messages devuelve 404 desde Nginx/Laravel.
        Route::get('/{any}', fn() => view('app'))->where('any', '.*');
    });

/*
|--------------------------------------------------------------------------
| Redirección legacy /dashboard → /app
| Mantiene compatibilidad si alguien tenía el link guardado.
|--------------------------------------------------------------------------
*/
Route::get('/dashboard', fn() => redirect('/app'))
    ->middleware('auth')
    ->name('dashboard');
