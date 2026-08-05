<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La raíz redirige al login: no hay landing pública en el CRM.
     */
    public function test_the_application_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    /**
     * El login de invitados responde correctamente.
     */
    public function test_the_login_page_renders(): void
    {
        $this->get('/login')->assertStatus(200);
    }
}
