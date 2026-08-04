<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Regresión: la SPA se servía pero quedaba vacía en el deploy.
 *
 * Causa: SANCTUM_STATEFUL_DOMAINS enumeraba dominios a mano, así que el dominio
 * real de producción no era "stateful". Las llamadas a /api/* devolvían 401 aun
 * con sesión válida → sidebar sin usuario, listas vacías, y el interceptor de
 * axios entrando en bucle de redirección al login.
 *
 * Fix: config/sanctum.php anexa siempre Sanctum::currentRequestHost(), seguro
 * porque la SPA se sirve desde el mismo origen que la API.
 */
class SpaApiStatefulTest extends TestCase
{
    use RefreshDatabase;

    /** El host que sirve la SPA siempre debe considerarse stateful. */
    public function test_current_request_host_is_stateful(): void
    {
        $request = \Illuminate\Http\Request::create('https://crm.reinoaromas.com/api/user');
        $request->headers->set('referer', 'https://crm.reinoaromas.com/app');

        $this->assertTrue(
            EnsureFrontendRequestsAreStateful::fromFrontend($request),
            'El host propio del deploy debe ser stateful, si no /api/* responde 401 y la SPA queda vacía.'
        );
    }

    /** Un dominio ajeno NO debe ser stateful: el fix no debe abrir la puerta. */
    public function test_foreign_origin_is_not_stateful(): void
    {
        $request = \Illuminate\Http\Request::create('https://crm.reinoaromas.com/api/user');
        $request->headers->set('referer', 'https://atacante.example.com/x');

        $this->assertFalse(
            EnsureFrontendRequestsAreStateful::fromFrontend($request),
            'Un origen externo nunca debe recibir autenticación por cookie de sesión.'
        );
    }

    /** Con sesión activa, la SPA obtiene el usuario desde /api/user. */
    public function test_authenticated_session_can_read_api_user(): void
    {
        $user = User::factory()->create([
            'role'      => 'superadmin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    /** Las sub-rutas del Vue Router devuelven el shell, no un 404. */
    public function test_spa_subroutes_serve_the_shell(): void
    {
        $user = User::factory()->create([
            'role'      => 'superadmin',
            'is_active' => true,
        ]);

        foreach (['/app', '/app/messages', '/app/settings/accounts', '/app/settings/users'] as $uri) {
            $this->actingAs($user)
                ->get($uri)
                ->assertOk()
                ->assertSee('id="app"', false);
        }
    }
}
