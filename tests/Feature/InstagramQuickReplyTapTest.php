<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InstagramAutomation;
use App\Models\InstagramQuickReplyMenu;
use App\Models\Message;
use App\Services\Meta\InstagramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tocar una burbuja de un menú (quick reply) de Instagram.
 *
 * Meta NO lo entrega como postback sino como un `message` normal con
 * `message.quick_reply.payload`. Cuando el código solo esperaba postbacks, la
 * persona tocaba "Curso Valencia", veía su toque en el chat y no recibía nada.
 */
class InstagramQuickReplyTapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.instagram_account_id', '17841407844220949');
        config()->set('services.meta.instagram_access_token', 'token-de-prueba');
    }

    private function toque(string $payload, string $mid = 'mid.toque1'): array
    {
        return [
            'object' => 'instagram',
            'entry'  => [[
                'id'        => '17841407844220949',
                'time'      => 1757500000,
                'messaging' => [[
                    'sender'    => ['id' => '9988776655'],
                    'recipient' => ['id' => '17841407844220949'],
                    'timestamp' => 1757500000,
                    'message'   => [
                        'mid'         => $mid,
                        'text'        => 'Curso Valencia',
                        'quick_reply' => ['payload' => $payload],
                    ],
                ]],
            ]],
        ];
    }

    private function opcion(): InstagramAutomation
    {
        $menu = InstagramQuickReplyMenu::create([
            'name'      => 'INFO CURSOS',
            'body'      => '¿Qué te gustaría saber?',
            'is_active' => true,
        ]);

        return InstagramAutomation::create([
            'kind'          => InstagramAutomation::KIND_QUICK_REPLY,
            'menu_group_id' => $menu->id,
            'title'         => 'Curso Valencia',
            'payload'       => 'qr_valencia',
            'response_type' => InstagramAutomation::RESPONSE_TEXT,
            'response_text' => 'Info del curso en Valencia',
            'is_active'     => true,
        ]);
    }

    public function test_tocar_una_burbuja_responde_con_la_opcion(): void
    {
        Queue::fake();
        Http::fake(['graph.instagram.com/*' => Http::response(['username' => 'clienta'], 200)]);

        $opcion = $this->opcion();

        app(InstagramService::class)->processWebhookPayload($this->toque('qr_valencia'));

        $salientes = Message::query()->where('direction', Message::DIRECTION_OUTBOUND)->get();

        $this->assertCount(1, $salientes);
        $this->assertSame('Info del curso en Valencia', $salientes->first()->body);
        $this->assertSame(1, $opcion->fresh()->hits);
    }

    public function test_la_respuesta_sustituye_los_marcadores_de_la_plantilla(): void
    {
        Queue::fake();
        Http::fake(['graph.instagram.com/*' => Http::response(['username' => 'clienta', 'name' => 'Ana Paula'], 200)]);

        $opcion = $this->opcion();
        $opcion->update(['response_text' => '¡Hola {{nombre}}! Info del curso']);

        app(InstagramService::class)->processWebhookPayload($this->toque('qr_valencia'));

        $saliente = Message::query()->where('direction', Message::DIRECTION_OUTBOUND)->first();

        $this->assertNotNull($saliente);
        $this->assertStringNotContainsString('{{', $saliente->body);
        $this->assertStringStartsWith('¡Hola ', $saliente->body);
    }

    public function test_un_reintento_de_meta_no_duplica_la_respuesta(): void
    {
        Queue::fake();
        Http::fake(['graph.instagram.com/*' => Http::response(['username' => 'clienta'], 200)]);

        $this->opcion();

        $servicio = app(InstagramService::class);
        $servicio->processWebhookPayload($this->toque('qr_valencia', 'mid.igual'));
        $servicio->processWebhookPayload($this->toque('qr_valencia', 'mid.igual'));

        $this->assertSame(
            1,
            Message::query()->where('direction', Message::DIRECTION_OUTBOUND)->count(),
        );
    }
}
