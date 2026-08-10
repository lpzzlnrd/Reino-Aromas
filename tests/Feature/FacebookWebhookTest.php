<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessMetaWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regresión: no existía webhook de Facebook.
 *
 * Solo había rutas de whatsapp, instagram y flows. Apuntar el webhook de la
 * Página a la URL de WhatsApp *parece* funcionar — el verify token es común a
 * las tres redes, así que Meta valida en verde — pero
 * WhatsAppWebhookController descarta todo lo que no traiga
 * object=whatsapp_business_account. Los mensajes de Messenger se perdían en
 * silencio, sin una sola línea en los logs.
 *
 * El Job que los procesa (ProcessMetaWebhookJob) ya existía: solo faltaba el
 * endpoint que lo dispara.
 */
class FacebookWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'app-secret-de-prueba';
    private const TOKEN = 'verify-token-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_secret' => self::SECRETO,
            'services.meta.webhook_verify_token' => self::TOKEN,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function enviarFirmado(array $payload, ?string $firma = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            '/api/webhooks/facebook',
            [],
            [],
            [],
            [
                'HTTP_X-Hub-Signature-256' => $firma ?? 'sha256=' . hash_hmac('sha256', $body, self::SECRETO),
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );
    }

    /**
     * El handshake de verificación.
     *
     * Nota: se envían hub_mode/hub_verify_token con guion bajo porque PHP
     * convierte los puntos de hub.mode en guiones bajos al parsear el query
     * string — así llegan realmente al controlador.
     */
    public function test_verifica_el_webhook_con_el_token_correcto(): void
    {
        $this->get('/api/webhooks/facebook?hub_mode=subscribe&hub_verify_token=' . self::TOKEN . '&hub_challenge=desafio123')
            ->assertOk()
            ->assertSee('desafio123');
    }

    public function test_rechaza_un_token_de_verificacion_incorrecto(): void
    {
        $this->get('/api/webhooks/facebook?hub_mode=subscribe&hub_verify_token=incorrecto&hub_challenge=desafio123')
            ->assertStatus(403);
    }

    public function test_procesa_un_mensaje_de_messenger(): void
    {
        Queue::fake();

        $this->enviarFirmado([
            'object' => 'page',
            'entry'  => [[
                'messaging' => [[
                    'sender'    => ['id' => 'psid-cliente-1'],
                    'recipient' => ['id' => 'id-de-la-pagina'],
                    'timestamp' => 1700000000000,
                    'message'   => ['mid' => 'mid.abc123', 'text' => 'Hola, quiero info'],
                ]],
            ]],
        ])->assertOk();

        Queue::assertPushed(ProcessMetaWebhookJob::class);
    }

    public function test_rechaza_una_firma_invalida(): void
    {
        Queue::fake();

        $this->enviarFirmado(
            ['object' => 'page', 'entry' => []],
            'sha256=firmafalsa',
        )->assertStatus(403);

        Queue::assertNothingPushed();
    }

    /** Sin firma no se procesa: la URL es pública. */
    public function test_rechaza_un_request_sin_firma(): void
    {
        Queue::fake();

        $this->postJson('/api/webhooks/facebook', ['object' => 'page', 'entry' => []])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    /**
     * Sin app secret configurado se rechaza en vez de dejar pasar: aceptar sin
     * validar abriría el endpoint a cualquiera que conozca la URL.
     */
    public function test_sin_app_secret_configurado_rechaza(): void
    {
        config(['services.meta.app_secret' => '']);
        Queue::fake();

        $this->enviarFirmado(['object' => 'page', 'entry' => []])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    /**
     * Un evento de otra red se ignora con 200, no con 4xx: un error haría que
     * Meta reintente indefinidamente algo que nunca vamos a procesar.
     */
    public function test_ignora_eventos_de_otras_redes_con_200(): void
    {
        Queue::fake();

        $this->enviarFirmado(['object' => 'whatsapp_business_account', 'entry' => []])
            ->assertOk();

        Queue::assertNothingPushed();
    }

    /**
     * El complemento del bug: el webhook de WhatsApp descarta los eventos de
     * Página. Por eso cada red necesita su propia URL.
     */
    public function test_el_webhook_de_whatsapp_descarta_los_eventos_de_pagina(): void
    {
        Queue::fake();

        $body = json_encode(['object' => 'page', 'entry' => []], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::SECRETO),
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        )->assertOk();

        // Responde 200 pero NO procesa: de ahí el descarte silencioso.
        Queue::assertNothingPushed();
    }
}
