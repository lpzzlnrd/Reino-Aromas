<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessMetaWebhookJob;
use App\Models\Contact;
use App\Services\Meta\FacebookAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los contactos de Messenger se guardaban todos como "Facebook User".
 *
 * No era un fallback de Meta que se disparaba mal: el webhook de Messenger
 * NUNCA trae el nombre — solo el PSID en sender.id. El nombre hay que pedirlo
 * aparte a la User Profile API, y ProcessMetaWebhookJob escribía el literal
 * 'Facebook User' sin consultar nada. FacebookAccountService::fetchUserProfile()
 * ya existía y hacía exactamente esa llamada; el Job simplemente no la usaba.
 *
 * El nombre de relleno NO se puede eliminar: la User Profile API exige Advanced
 * Access de "Business Asset User Profile Access" (App Review), y aun con eso
 * devuelve vacío para cuentas creadas con número de teléfono (error 2018218) o
 * para quien llegó por Click-to-Messenger y todavía no respondió.
 */
class FacebookContactNameTest extends TestCase
{
    use RefreshDatabase;

    private const PSID = '7654321098765432';
    private const PAGE_ID = '111877921850663';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.facebook.page_id' => self::PAGE_ID,
            'services.meta.facebook.page_access_token' => 'page-token-de-prueba',
        ]);
    }

    /**
     * @param array{name: string, profile_pic: ?string}|null $perfil
     */
    private function simularPerfil(?array $perfil): void
    {
        $doble = $this->createMock(FacebookAccountService::class);
        $doble->method('fetchUserProfile')->willReturn($perfil);

        $this->app->instance(FacebookAccountService::class, $doble);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $mid = 'mid.NOMBRE_UNO'): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => self::PAGE_ID,
                'messaging' => [[
                    'sender' => ['id' => self::PSID],
                    'recipient' => ['id' => self::PAGE_ID],
                    'timestamp' => 1756200000000,
                    'message' => ['mid' => $mid, 'text' => 'Hola, quiero info'],
                ]],
            ]],
        ];
    }

    public function test_guarda_el_nombre_real_que_devuelve_la_api_de_perfiles(): void
    {
        $this->simularPerfil(['name' => 'Ana Pérez', 'profile_pic' => 'https://cdn.fb/ana.jpg']);

        (new ProcessMetaWebhookJob($this->payload()))
            ->handle(app(FacebookAccountService::class));

        $contacto = Contact::where('channel_id', self::PSID)->first();

        $this->assertNotNull($contacto);
        $this->assertSame('Ana Pérez', $contacto->display_name);
        $this->assertSame('https://cdn.fb/ana.jpg', $contacto->profile_picture_url);
    }

    public function test_usa_el_nombre_de_relleno_cuando_meta_no_da_el_perfil(): void
    {
        // Es el caso sin Advanced Access: Meta responde {} y fetchUserProfile
        // devuelve null. El mensaje debe guardarse igual.
        $this->simularPerfil(null);

        (new ProcessMetaWebhookJob($this->payload()))
            ->handle(app(FacebookAccountService::class));

        $contacto = Contact::where('channel_id', self::PSID)->first();

        $this->assertNotNull($contacto, 'Sin nombre el contacto se crea igual: perder el mensaje sería peor.');
        $this->assertSame(ProcessMetaWebhookJob::NOMBRE_DESCONOCIDO, $contacto->display_name);
    }

    public function test_corrige_un_contacto_que_quedo_con_el_nombre_de_relleno(): void
    {
        // Contacto guardado antes de tener Advanced Access.
        Contact::create([
            'channel' => Contact::CHANNEL_FACEBOOK,
            'channel_id' => self::PSID,
            'display_name' => ProcessMetaWebhookJob::NOMBRE_DESCONOCIDO,
            'first_seen_at' => now()->subMonth(),
            'last_seen_at' => now()->subMonth(),
        ]);

        $this->simularPerfil(['name' => 'Luis Rodríguez', 'profile_pic' => null]);

        (new ProcessMetaWebhookJob($this->payload('mid.CORRIGE')))
            ->handle(app(FacebookAccountService::class));

        // Sin esto los contactos viejos se quedarían con "Facebook User" para
        // siempre, aunque la API ya responda con el nombre real.
        $this->assertSame(
            'Luis Rodríguez',
            Contact::where('channel_id', self::PSID)->first()->display_name,
        );
    }

    public function test_no_pisa_un_nombre_que_ya_estaba_bien(): void
    {
        Contact::create([
            'channel' => Contact::CHANNEL_FACEBOOK,
            'channel_id' => self::PSID,
            'display_name' => 'Nombre Editado A Mano',
            'first_seen_at' => now()->subMonth(),
            'last_seen_at' => now()->subMonth(),
        ]);

        $this->simularPerfil(['name' => 'Ana Pérez', 'profile_pic' => null]);

        (new ProcessMetaWebhookJob($this->payload('mid.NO_PISA')))
            ->handle(app(FacebookAccountService::class));

        // La corrección solo aplica al nombre de relleno. Un nombre que alguien
        // ajustó en el CRM no se sobrescribe con lo que diga Meta.
        $this->assertSame(
            'Nombre Editado A Mano',
            Contact::where('channel_id', self::PSID)->first()->display_name,
        );
    }

    public function test_no_llama_a_la_api_si_falta_el_page_token(): void
    {
        config(['services.meta.facebook.page_access_token' => '']);

        $doble = $this->createMock(FacebookAccountService::class);
        // Sin token la llamada sería un 400 garantizado: hay que cortar antes.
        $doble->expects($this->never())->method('fetchUserProfile');
        $this->app->instance(FacebookAccountService::class, $doble);

        (new ProcessMetaWebhookJob($this->payload('mid.SIN_TOKEN')))
            ->handle(app(FacebookAccountService::class));

        $this->assertSame(
            ProcessMetaWebhookJob::NOMBRE_DESCONOCIDO,
            Contact::where('channel_id', self::PSID)->first()->display_name,
        );
    }
}
