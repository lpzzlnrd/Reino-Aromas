<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Genera el par de claves RSA que cifra el canal de datos de los Flows.
 *
 * Meta exige RSA de 2048 bits. La privada se guarda cifrada con una
 * passphrase y NUNCA sale del servidor; la pública se sube a Meta con
 * `flows:upload-key`.
 */
class FlowsGenerateKeysCommand extends Command
{
    protected $signature = 'flows:generate-keys
                            {--passphrase= : Contraseña para cifrar la clave privada (se genera una si se omite)}
                            {--show-private : Imprime también la privada, para copiarla al .env}';

    protected $description = 'Genera el par de claves RSA 2048 para el endpoint de WhatsApp Flows';

    public function handle(): int
    {
        $passphrase = (string) ($this->option('passphrase') ?: bin2hex(random_bytes(16)));

        $this->info('Generando par RSA de 2048 bits...');

        $recurso = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($recurso === false) {
            $this->error('openssl_pkey_new falló. ¿Está habilitada la extensión openssl?');

            return self::FAILURE;
        }

        $privada = '';
        openssl_pkey_export($recurso, $privada, $passphrase);

        $detalles = openssl_pkey_get_details($recurso);
        $publica  = $detalles['key'] ?? '';

        if ($publica === '') {
            $this->error('No se pudo extraer la clave pública.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<fg=green>Par generado.</> Guarda estos valores en el .env del VPS:');
        $this->newLine();

        // La privada lleva saltos de línea, que en un .env hay que escapar
        // como \n dentro de comillas dobles o Laravel lee solo la primera línea.
        $this->line('<fg=yellow>FLOWS_PASSPHRASE=</>' . $passphrase);
        $this->newLine();
        $this->line('<fg=yellow>FLOWS_PRIVATE_KEY=</>"' . str_replace("\n", '\n', trim($privada)) . '"');

        if (! $this->option('show-private')) {
            $this->newLine();
            $this->comment('(la privada se imprimió en una sola línea, lista para pegar en el .env)');
        }

        $this->newLine();
        $this->line('<fg=cyan>Clave pública</> (la sube `php artisan flows:upload-key`):');
        $this->newLine();
        $this->line($publica);

        $this->newLine();
        $this->warn('La clave privada NO debe versionarse en git. Solo va al .env del servidor.');
        $this->comment('Siguiente paso: php artisan flows:upload-key');

        return self::SUCCESS;
    }
}
