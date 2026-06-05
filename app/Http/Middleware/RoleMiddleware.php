<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Restringe rutas a uno o varios roles específicos.
// Uso en rutas: Route::middleware(['role:superadmin']) o Route::middleware(['role:superadmin,administrador'])
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles, strict: true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'No autorizado.'], 403);
            }

            abort(403, 'No tienes permiso para acceder a esta sección.');
        }

        return $next($request);
    }
}
