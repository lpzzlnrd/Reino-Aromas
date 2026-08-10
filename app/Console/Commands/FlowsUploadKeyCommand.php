<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Sube la clave pública a Meta y consulta su estado de firma.
 *
 * Meta la firma automáticamente al recibirla. Hay que volver a subirla:
 *   - al re-registrar el número de teléfono
 *   - si llegan webhooks con 'public-key-missing' o
 *     'public-key-signature-verification'
 */
class FlowsUploadKeyCommand extends Command
{
    protected $signature = 'flows:upload-key
                            {--check : Solo consulta la clave actual, sin subir nada}';

    protected $description = 'Sube a Meta la clave pública del endpoint de WhatsApp Flows';

    public function handle(): int
    {
        $phoneNumberId = (string) config('services.meta.whatsapp_phone_number_id');
        $token         = (string) config('services.meta.access_token');
        $version       = (string) config('services.meta.graph_api_version', 'v21.0');

        if ($phoneNumberId === '' || $token === '') {
            $this->error('Faltan META_WHATSAPP_PHONE_NUMBER_ID o META_ACCESS_TOKEN en el .env.');
            $this->comment('Ver docs/META_APP_SETUP.md sección 4.');

            return self::FAILURE;
        }

        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/whatsapp_business_encryption";

        // --check: consultar la clave que Meta tiene guardada.
        if ($this->option('check')) {
            $res = Http::withToken($token)->get($url);

            if ($res->failed()) {
                $this->error('Meta respondió ' . $res->status());
                $this->line($res->body());

                return self::FAILURE;
            }

            $estado = $res->json('business_public_key_signature_status');

            $this->info('Estado de la firma: ' . ($estado ?? 'desconocido'));

            // MISMATCH significa que la pública en Meta no corresponde a
            // nuestra privada: hay que volver a subirla.
            if ($estado === 'MISMATCH') {
                $this->warn('La clave no coincide. Corre este comando sin --check para resubirla.');
            }

            return self::SUCCESS;
        }

        // Derivar la pública desde la privada del .env: así siempre se sube la
        // que corresponde a la que el endpoint usa para descifrar.
        $privadaPem = (string) config('services.meta.flows.private_key');
        $passphrase = (string) config('services.meta.flows.passphrase');

        if ($privadaPem === '') {
            $this->error('FLOWS_PRIVATE_KEY no está configurada.');
            $this->comment('Genera el par con: php artisan flows:generate-keys');

            return self::FAILURE;
        }

        $privada = openssl_pkey_get_private($privadaPem, $passphrase);

        if ($privada === false) {
            $this->error('No se pudo abrir la clave privada. ¿La passphrase es correcta?');

            return self::FAILURE;
        }

        $publica = openssl_pkey_get_details($privada)['key'] ?? '';

        if ($publica === '') {
            $this->error('No se pudo derivar la clave pública.');

            return self::FAILURE;
        }

        $this->info('Subiendo la clave pública a Meta...');

        $res = Http::withToken($token)
            ->asForm()
            ->post($url, ['business_public_key' => $publica]);

        if ($res->failed()) {
            $this->error('Meta respondió ' . $res->status());
            $this->line($res->body());

            return self::FAILURE;
        }

        $this->info('Clave subida correctamente.');
        $this->comment('Verifica el estado con: php artisan flows:upload-key --check');

        return self::SUCCESS;
    }
}
