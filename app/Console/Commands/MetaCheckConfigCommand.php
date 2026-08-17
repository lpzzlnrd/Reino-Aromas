<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Meta\MetaCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Dice de un golpe qué credenciales de Meta faltan y, con --probe, si las que
 * hay sirven de verdad.
 *
 * Existe porque el orden de arranque real es: primero llegan las credenciales,
 * después se descubre que una está mal. Sin esto ese descubrimiento pasa por un
 * mensaje que no llega a un cliente y un error enterrado en el log de la cola.
 */
class MetaCheckConfigCommand extends Command
{
    protected $signature = 'meta:check
                            {--probe : Además llama a la Graph API para comprobar que el token sirve}';

    protected $description = 'Comprueba qué credenciales de Meta están configuradas y si son válidas';

    /**
     * Qué hace falta para cada canal. Se agrupa así porque el CRM puede operar
     * con WhatsApp y sin Instagram: no todo lo ausente es un problema.
     *
     * @var array<string, list<string>>
     */
    private const POR_CANAL = [
        'Común (webhooks + firma)' => ['app_id', 'app_secret', 'webhook_verify_token'],
        'WhatsApp'                 => ['access_token', 'whatsapp_phone_number_id'],
        'Instagram'                => ['access_token', 'instagram_account_id'],
        'Facebook Messenger'       => ['facebook.page_id', 'facebook.page_access_token'],
    ];

    public function handle(MetaCredentials $credentials): int
    {
        $this->info('Configuración de Meta');
        $this->line('Graph API: ' . $credentials->versionApi());
        $this->newLine();

        $filas = [];
        $listos = [];

        foreach (self::POR_CANAL as $canal => $claves) {
            $faltan = $credentials->faltantes($claves);

            $filas[] = [
                $canal,
                $faltan === [] ? 'LISTO' : 'INCOMPLETO',
                $faltan === [] ? '—' : implode(', ', $faltan),
            ];

            if ($faltan === []) {
                $listos[] = $canal;
            }
        }

        $this->table(['Canal', 'Estado', 'Variables que faltan'], $filas);

        // El aviso se ata a app_secret concretamente, no al bloque entero: sin
        // verify_token falla solo el alta del webhook (una vez), pero sin
        // app_secret se rechaza CADA evento entrante. No son lo mismo.
        if (! $credentials->tiene('app_secret')) {
            $this->newLine();
            $this->warn('Sin META_APP_SECRET los webhooks entrantes se RECHAZAN: no se puede verificar su firma.');
        }

        if (! $credentials->tiene('webhook_verify_token')) {
            $this->newLine();
            $this->warn('Sin META_WEBHOOK_VERIFY_TOKEN el alta del webhook en el dashboard de Meta devolverá 403.');
        }

        if ($this->option('probe')) {
            $this->newLine();
            $this->probar($credentials);
        }

        // Se devuelve 0 aunque falten credenciales: el comando informa, no
        // valida un despliegue. Un exit distinto de cero rompería un pipeline
        // que lo llame como paso de diagnóstico.
        $this->newLine();
        $this->line(count($listos) . ' de ' . count(self::POR_CANAL) . ' bloques configurados.');

        return self::SUCCESS;
    }

    /**
     * Llama a la Graph API para distinguir "configurado" de "funciona".
     *
     * Una credencial presente pero caducada o de otra app pasa el chequeo de
     * arriba y falla en producción; esto lo detecta antes.
     */
    private function probar(MetaCredentials $credentials): void
    {
        if (! $credentials->tiene('access_token')) {
            $this->warn('Sin META_ACCESS_TOKEN no hay nada que probar.');

            return;
        }

        $this->info('Probando el token contra la Graph API...');

        try {
            $response = Http::withToken($credentials->obtener('access_token'))
                ->timeout(15)
                ->get($credentials->urlGraph('me'), ['fields' => 'id,name']);

            if ($response->successful()) {
                $this->line('  Token válido. Responde: ' . json_encode($response->json()));
            } else {
                // El campo 'message' de Meta es el que dice si el token caducó,
                // si le faltan permisos o si es de otra app.
                $this->error('  Token rechazado: ' . ($response->json('error.message') ?? $response->status()));
            }
        } catch (\Throwable $e) {
            $this->error('  No se pudo contactar la Graph API: ' . $e->getMessage());
        }
    }
}
