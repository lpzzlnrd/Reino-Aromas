<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

// Redirige la raíz al login
Route::get('/', fn() => redirect()->route('login'));

/*
|--------------------------------------------------------------------------
| Rutas de autenticación (solo para invitados)
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
| Rutas protegidas del CRM
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:superadmin,administrador'])->group(function () {
    Route::get('/dashboard', fn() => view('dashboard'))->name('dashboard');
});
