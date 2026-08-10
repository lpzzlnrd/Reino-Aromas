<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendFacebookMessageJob;
use App\Jobs\SendInstagramMessageJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regresión: responder un chat salía siempre por Facebook.
 *
 * routes/api.php cableaba POST /conversations/{c}/messages directo a
 * FacebookMessageController sin mirar el canal del contacto, así que contestar
 * un WhatsApp intentaba enviarlo por Messenger. Peor aún: ese controlador
 * usaba Contact::CHANNEL_FACEBOOK, una constante que no existía en el modelo,
 * de modo que la ruta lanzaba un Error fatal en cuanto se usaba.
 *
 * Fix: OutboundMessageService resuelve el Job según contact.channel, y las
 * constantes viven en los modelos.
 */
class OutboundMessageChannelTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create([
            'role'      => 'administrador',
            'is_active' => true,
        ]);
    }

    private function conversacionEnCanal(string $canal): Conversation
    {
        $contact = Contact::create([
            'channel'       => $canal,
            'channel_id'    => "ext-{$canal}-1",
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

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function canales(): array
    {
        return [
            'whatsapp'  => [Contact::CHANNEL_WHATSAPP, SendWhatsAppMessageJob::class],
            'instagram' => [Contact::CHANNEL_INSTAGRAM, SendInstagramMessageJob::class],
            'facebook'  => [Contact::CHANNEL_FACEBOOK, SendFacebookMessageJob::class],
        ];
    }

    /**
     * Cada canal despacha su propio Job.
     *
     * @param class-string $jobEsperado
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('canales')]
    public function test_cada_canal_despacha_su_job(string $canal, string $jobEsperado): void
    {
        Queue::fake();

        $conversation = $this->conversacionEnCanal($canal);

        $this->actingAs($this->agente())
            ->postJson("/api/meta/conversations/{$conversation->id}/messages", [
                'body' => 'Hola, gracias por escribir.',
            ])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('message.channel', $canal)
            ->assertJsonPath('message.status', Message::STATUS_PENDING);

        Queue::assertPushed($jobEsperado);
    }

    /** El bug exacto: un WhatsApp no debe salir por Facebook. */
    public function test_whatsapp_no_sale_por_facebook(): void
    {
        Queue::fake();

        $conversation = $this->conversacionEnCanal(Contact::CHANNEL_WHATSAPP);

        $this->actingAs($this->agente())
            ->postJson("/api/meta/conversations/{$conversation->id}/messages", ['body' => 'Hola'])
            ->assertStatus(202);

        Queue::assertPushed(SendWhatsAppMessageJob::class);
        Queue::assertNotPushed(SendFacebookMessageJob::class);
        Queue::assertNotPushed(SendInstagramMessageJob::class);
    }

    /**
     * El mensaje se persiste antes de encolar: si la cola está caída el chat
     * muestra el mensaje en `pending` en vez de perderlo.
     */
    public function test_el_mensaje_se_persiste_antes_de_encolar(): void
    {
        Queue::fake();

        $conversation = $this->conversacionEnCanal(Contact::CHANNEL_WHATSAPP);
        $agente = $this->agente();

        $this->actingAs($agente)
            ->postJson("/api/meta/conversations/{$conversation->id}/messages", ['body' => 'Texto guardado'])
            ->assertStatus(202);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_OUTBOUND,
            'channel'         => Contact::CHANNEL_WHATSAPP,
            'body'            => 'Texto guardado',
            'status'          => Message::STATUS_PENDING,
            'sender_user_id'  => $agente->id,
        ]);
    }

    /** Enviar actualiza last_message_at para que el chat suba en la bandeja. */
    public function test_enviar_actualiza_last_message_at(): void
    {
        Queue::fake();

        $conversation = $this->conversacionEnCanal(Contact::CHANNEL_WHATSAPP);
        $conversation->forceFill(['last_message_at' => now()->subDay()])->save();
        $antes = $conversation->fresh()->last_message_at;

        $this->actingAs($this->agente())
            ->postJson("/api/meta/conversations/{$conversation->id}/messages", ['body' => 'Nuevo'])
            ->assertStatus(202);

        $this->assertTrue(
            $conversation->fresh()->last_message_at->greaterThan($antes),
            'last_message_at debe avanzar o el chat no sube en la bandeja.'
        );
    }

    public function test_el_cuerpo_es_obligatorio(): void
    {
        Queue::fake();

        $conversation = $this->conversacionEnCanal(Contact::CHANNEL_WHATSAPP);

        $this->actingAs($this->agente())
            ->postJson("/api/meta/conversations/{$conversation->id}/messages", ['body' => ''])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /** 4096 es el límite de WhatsApp, el más bajo de los tres canales. */
    public function test_el_cuerpo_se_limita_a_4096_caracteres(): void
    {
        Queue::fake();

        $conversation = $this->conversacionEnCanal(Contact::CHANNEL_WHATSAPP);
        $agente = $this->agente();

        $this->actingAs($agente)
            ->postJson("/api/meta/conversations/{$conversation->id}/messages", [
                'body' => str_repeat('a', 4097),
            ])
            ->assertStatus(422);

        $this->actingAs($agente)
            ->postJson("/api/meta/conversations/{$conversation->id}/messages", [
                'body' => str_repeat('a', 4096),
            ])
            ->assertStatus(202);
    }

    public function test_un_usuario_desactivado_no_puede_enviar(): void
    {
        Queue::fake();

        $conversation = $this->conversacionEnCanal(Contact::CHANNEL_WHATSAPP);
        $inactivo = User::factory()->create(['role' => 'administrador', 'is_active' => false]);

        $this->actingAs($inactivo)
            ->postJson("/api/meta/conversations/{$conversation->id}/messages", ['body' => 'Hola'])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_sin_sesion_responde_401(): void
    {
        $conversation = $this->conversacionEnCanal(Contact::CHANNEL_WHATSAPP);

        $this->postJson("/api/meta/conversations/{$conversation->id}/messages", ['body' => 'Hola'])
            ->assertStatus(401);
    }
}
