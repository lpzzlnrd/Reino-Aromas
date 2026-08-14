<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Administradores del CRM.
 *
 * Regresión que motivó el archivo: el listado ordenaba con FIELD(), que es
 * exclusivo de MySQL. No había ni un test que tocara /api/users, así que la
 * consulta nunca se ejecutó en SQLite — donde FIELD no existe y revienta. En
 * producción funcionaba, y por eso pasó desapercibido.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create([
            'role'      => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function administrador(string $nombre = 'Admin'): User
    {
        return User::factory()->create([
            'name'      => $nombre,
            'role'      => User::ROLE_ADMINISTRADOR,
            'is_active' => true,
        ]);
    }

    /**
     * El que caza la regresión: si vuelve el FIELD(), este test falla con un
     * error de SQL en vez de pasar.
     */
    public function test_el_listado_ordena_por_rol_sin_sql_especifico_de_mysql(): void
    {
        // Se crean en orden inverso al esperado para que el orden no sea
        // casualidad del id.
        $this->administrador('Zulema');
        $this->administrador('Ana');
        $super = User::factory()->create([
            'name'      => 'Beto',
            'role'      => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $listado = $this->actingAs($super)
            ->getJson('/api/users')
            ->assertOk()
            ->json();

        // Superadmin primero aunque su nombre empiece por B, y los
        // administradores después por orden alfabético.
        $this->assertSame(
            ['Beto', 'Ana', 'Zulema'],
            array_column($listado, 'name'),
        );
    }

    public function test_el_listado_no_expone_la_contrasena(): void
    {
        $super = $this->superadmin();

        $usuario = $this->actingAs($super)->getJson('/api/users')->assertOk()->json('0');

        $this->assertArrayNotHasKey('password', $usuario);
        $this->assertArrayNotHasKey('remember_token', $usuario);
        // Y sí trae lo que la vista necesita.
        foreach (['id', 'name', 'email', 'role', 'is_active'] as $campo) {
            $this->assertArrayHasKey($campo, $usuario);
        }
    }

    public function test_un_superadmin_puede_crear_administradores(): void
    {
        $this->actingAs($this->superadmin())
            ->postJson('/api/users', [
                'name'                  => 'Nueva Agente',
                'email'                 => 'agente@reinoaromas.tech',
                'password'              => 'clave-segura-123',
                'password_confirmation' => 'clave-segura-123',
                'role'                  => User::ROLE_ADMINISTRADOR,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'agente@reinoaromas.tech',
            'role'  => User::ROLE_ADMINISTRADOR,
        ]);
    }

    /** Un administrador normal no debe poder crear usuarios. */
    public function test_un_administrador_no_puede_crear_usuarios(): void
    {
        $this->actingAs($this->administrador())
            ->postJson('/api/users', [
                'name'                  => 'Intruso',
                'email'                 => 'intruso@reinoaromas.tech',
                'password'              => 'clave-segura-123',
                'password_confirmation' => 'clave-segura-123',
                'role'                  => User::ROLE_SUPERADMIN,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'intruso@reinoaromas.tech']);
    }

    public function test_toggle_active_alterna_el_estado(): void
    {
        $super = $this->superadmin();
        $agente = $this->administrador();

        $this->actingAs($super)
            ->patchJson("/api/users/{$agente->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->actingAs($super)
            ->patchJson("/api/users/{$agente->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('is_active', true);
    }

    /**
     * Actualizar sin mandar contraseña no debe borrarla: el controller
     * descarta la clave cuando viene vacía.
     */
    public function test_actualizar_sin_contrasena_no_la_pisa(): void
    {
        $super = $this->superadmin();
        $agente = $this->administrador();
        $hashOriginal = $agente->password;

        $this->actingAs($super)
            ->putJson("/api/users/{$agente->id}", [
                'name'  => 'Nombre Corregido',
                'email' => $agente->email,
                'role'  => User::ROLE_ADMINISTRADOR,
            ])
            ->assertOk();

        $agente->refresh();
        $this->assertSame('Nombre Corregido', $agente->name);
        $this->assertSame($hashOriginal, $agente->password, 'La contraseña no debía cambiar.');
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
    }
}
