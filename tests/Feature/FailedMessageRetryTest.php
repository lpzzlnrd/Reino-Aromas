<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendFacebookMessageJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\OutboundMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Reintento de mensajes fallidos.
 *
 * El hueco que cierra: un mensaje que fallaba quedaba con status 'failed' y una
 * línea en el log, y el agente no tenía forma de reintentarlo — su única opción
 * era escribir el texto otra vez a mano. Nadie se enteraba salvo que abriera ese
 * chat concreto.
 */
class FailedMessageRetryTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create([
            'role'      => User::ROLE_ADMINISTRADOR,
            'is_active' => true,
        ]);
    }

    private function conversacionEnCanal(string $canal): Conversation
    {
        $contact = Contact::create([
            'channel'       => $canal,
            'channel_id'    => 'ext-' . $canal . '-' . uniqid(),
            'display_name'  => 'Contacto de prueba',
            'city'          => 'caracas',
            'phone'         => '+584121234567',
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
        ]);

        return $contact->conversations()->create([
            'status'            => Conversation::STATUS_OPEN,
            'within_24h_window' => true,
            'last_message_at'   => now(),
        ]);
    }

    /** Un mensaje saliente ya fallado, como lo deja el Job al agotar reintentos. */
    private function mensajeFallido(string $canal = Contact::CHANNEL_WHATSAPP): Message
    {
        return $this->conversacionEnCanal($canal)->messages()->create([
            'direction'     => Message::DIRECTION_OUTBOUND,
            'channel'       => $canal,
            'type'          => Message::TYPE_TEXT,
            'body'          => 'Hola, te escribo del curso',
            'status'        => Message::STATUS_FAILED,
            'failed_reason' => 'Meta devolvio un error temporal.',
        ]);
    }

    public function test_reintentar_vuelve_a_encolar_el_job_del_canal(): void
    {
        Queue::fake();

        $mensaje = $this->mensajeFallido();

        $response = $this->actingAs($this->agente())
            ->postJson("/api/meta/messages/{$mensaje->id}/retry");

        $response->assertStatus(202);
        $response->assertJsonPath('queued', true);

        Queue::assertPushed(SendWhatsAppMessageJob::class);
        Queue::assertNotPushed(SendFacebookMessageJob::class);
    }

    /**
     * Vuelve a 'pending' y limpia la razón: si falla otra vez, el motivo nuevo
     * es el que importa. Y el agente ve que se está reintentando en vez de una
     * burbuja roja que no cambia.
     */
    public function test_reintentar_pasa_a_pending_y_limpia_la_razon(): void
    {
        Queue::fake();

        $mensaje = $this->mensajeFallido();

        $this->actingAs($this->agente())
            ->postJson("/api/meta/messages/{$mensaje->id}/retry")
            ->assertStatus(202);

        $mensaje->refresh();

        $this->assertSame(Message::STATUS_PENDING, $mensaje->status);
        $this->assertNull($mensaje->failed_reason);
    }

    /**
     * NO se duplica el registro: dos burbujas para algo que el cliente vio una
     * vez (o ninguna) romperían el chat y la correlación con el external_id.
     */
    public function test_reintentar_no_crea_un_mensaje_nuevo(): void
    {
        Queue::fake();

        $mensaje = $this->mensajeFallido();

        $this->actingAs($this->agente())
            ->postJson("/api/meta/messages/{$mensaje->id}/retry");

        $this->assertDatabaseCount('messages', 1);
    }

    /** Reintentar un 'sent' lo enviaría dos veces al cliente. */
    public function test_no_se_puede_reintentar_un_mensaje_ya_enviado(): void
    {
        Queue::fake();

        $mensaje = $this->mensajeFallido();
        $mensaje->forceFill(['status' => Message::STATUS_SENT])->save();

        $response = $this->actingAs($this->agente())
            ->postJson("/api/meta/messages/{$mensaje->id}/retry");

        // 409 y no 422: el problema es el estado, no el formato de la petición.
        $response->assertStatus(409);
        // Especifico y no assertNothingPushed: guardar un Message dispara el
        // BroadcastEvent del tiempo real, que es legitimo y no un envio.
        Queue::assertNotPushed(SendWhatsAppMessageJob::class);
    }

    /** Un 'pending' ya tiene su Job en la cola: reintentar lo duplicaría. */
    public function test_no_se_puede_reintentar_un_mensaje_pendiente(): void
    {
        Queue::fake();

        $mensaje = $this->mensajeFallido();
        $mensaje->forceFill(['status' => Message::STATUS_PENDING])->save();

        $this->actingAs($this->agente())
            ->postJson("/api/meta/messages/{$mensaje->id}/retry")
            ->assertStatus(409);

        Queue::assertNotPushed(SendWhatsAppMessageJob::class);
    }

    public function test_no_se_puede_reintentar_un_mensaje_entrante(): void
    {
        Queue::fake();

        $mensaje = $this->mensajeFallido();
        $mensaje->forceFill([
            'direction' => Message::DIRECTION_INBOUND,
            'status'    => Message::STATUS_FAILED,
        ])->save();

        $this->actingAs($this->agente())
            ->postJson("/api/meta/messages/{$mensaje->id}/retry")
            ->assertStatus(409);

        Queue::assertNotPushed(SendWhatsAppMessageJob::class);
    }

    public function test_el_listado_de_fallidos_devuelve_el_motivo_y_el_contacto(): void
    {
        $this->mensajeFallido();

        $response = $this->actingAs($this->agente())->getJson('/api/meta/messages/failed');

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('messages.0.failed_reason', 'Meta devolvio un error temporal.');
        $response->assertJsonPath('messages.0.contact_name', 'Contacto de prueba');
    }

    /** El listado solo trae fallidos salientes, no los enviados ni los entrantes. */
    public function test_el_listado_solo_incluye_fallidos_salientes(): void
    {
        $this->mensajeFallido();

        // Uno enviado correctamente, que no debe aparecer.
        $this->conversacionEnCanal(Contact::CHANNEL_INSTAGRAM)->messages()->create([
            'direction' => Message::DIRECTION_OUTBOUND,
            'channel'   => Contact::CHANNEL_INSTAGRAM,
            'type'      => Message::TYPE_TEXT,
            'body'      => 'este salio bien',
            'status'    => Message::STATUS_SENT,
        ]);

        $response = $this->actingAs($this->agente())->getJson('/api/meta/messages/failed');

        $response->assertJsonPath('total', 1);
        $response->assertJsonMissing(['body' => 'este salio bien']);
    }

    public function test_las_rutas_exigen_sesion(): void
    {
        $mensaje = $this->mensajeFallido();

        $this->getJson('/api/meta/messages/failed')->assertUnauthorized();
        $this->postJson("/api/meta/messages/{$mensaje->id}/retry")->assertUnauthorized();
    }

    /*
    |-------------------------------------------------------------------------
    | El comando de reintento masivo
    |-------------------------------------------------------------------------
    */

    public function test_el_comando_reencola_los_fallidos(): void
    {
        Queue::fake();

        $this->mensajeFallido();
        $this->mensajeFallido();

        $this->artisan('messages:retry-failed')
            ->expectsOutputToContain('Reencolados: 2')
            ->assertSuccessful();

        Queue::assertPushed(SendWhatsAppMessageJob::class, 2);
    }

    /** --dry-run muestra qué haría sin encolar nada. */
    public function test_el_dry_run_no_encola(): void
    {
        Queue::fake();

        $this->mensajeFallido();

        $this->artisan('messages:retry-failed --dry-run')
            ->assertSuccessful();

        Queue::assertNotPushed(SendWhatsAppMessageJob::class);
        // El mensaje sigue fallido: no se tocó nada.
        $this->assertDatabaseHas('messages', ['status' => Message::STATUS_FAILED]);
    }

    public function test_el_comando_filtra_por_canal(): void
    {
        Queue::fake();

        $this->mensajeFallido(Contact::CHANNEL_WHATSAPP);
        $this->mensajeFallido(Contact::CHANNEL_FACEBOOK);

        $this->artisan('messages:retry-failed --channel=whatsapp')
            ->expectsOutputToContain('Reencolados: 1')
            ->assertSuccessful();

        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
        Queue::assertNotPushed(SendFacebookMessageJob::class);
    }

    public function test_el_comando_respeta_el_limite(): void
    {
        Queue::fake();

        $this->mensajeFallido();
        $this->mensajeFallido();
        $this->mensajeFallido();

        $this->artisan('messages:retry-failed --limit=2')
            ->expectsOutputToContain('Reencolados: 2')
            ->assertSuccessful();

        Queue::assertPushed(SendWhatsAppMessageJob::class, 2);
    }

    public function test_el_comando_no_falla_si_no_hay_nada(): void
    {
        $this->artisan('messages:retry-failed')
            ->expectsOutputToContain('No hay mensajes fallidos')
            ->assertSuccessful();
    }

    /**
     * El servicio rechaza reintentar algo que no esta fallido, y ese rechazo es
     * lo que protege al cliente de recibir el mismo mensaje dos veces.
     *
     * Nota: no se prueba el caso "conversacion sin contacto" porque contact_id
     * es NOT NULL en la migracion, asi que ese estado no puede existir en la
     * base. La guarda del servicio queda como defensa en profundidad.
     */
    public function test_el_servicio_rechaza_un_mensaje_no_fallido(): void
    {
        Queue::fake();

        $mensaje = $this->mensajeFallido();
        $mensaje->forceFill(['status' => Message::STATUS_SENT])->save();

        $this->expectException(RuntimeException::class);

        app(OutboundMessageService::class)->retryFailedMessage($mensaje->fresh());
    }
}
