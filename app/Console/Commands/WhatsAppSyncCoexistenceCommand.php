<?php

namespace App\Console\Commands;

use App\Services\Meta\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Dispara la sincronización de contactos e historial de un número en
 * coexistencia (WhatsApp Business app + Cloud API).
 *
 * Se hace por comando y no automáticamente porque la operación es IRREPETIBLE:
 * cada tipo de sync se puede pedir una sola vez por número, y hay 24 horas de
 * plazo desde que el negocio conecta desde la app. Un job automático que se
 * disparara por error quemaría el único intento disponible.
 *
 * Uso normal, en este orden:
 *
 *   php artisan whatsapp:sync-coexistence --check
 *   php artisan whatsapp:sync-coexistence
 *
 * El --check confirma que el número quedó bien conectado ANTES de gastar los
 * intentos.
 */
class WhatsAppSyncCoexistenceCommand extends Command
{
    protected $signature = 'whatsapp:sync-coexistence
                            {--phone= : phone_number_id a sincronizar (por defecto, el del .env)}
                            {--check : Solo comprueba el estado, sin sincronizar nada}
                            {--only= : Sincroniza solo "contacts" o solo "history"}';

    protected $description = 'Pide a Meta los contactos y el historial de un número en coexistencia';

    public function handle(WhatsAppService $whatsapp): int
    {
        $phoneNumberId = $this->option('phone');

        // --check: diagnóstico puro, no consume ningún intento.
        $estado = $whatsapp->checkCoexistenceStatus($phoneNumberId);

        if (! $estado['success']) {
            $this->error('No se pudo consultar el número.');
            $this->line(json_encode($estado['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
            $this->comment('Revisá que META_WHATSAPP_PHONE_NUMBER_ID esté puesto y que el token tenga acceso a ese número.');

            return self::FAILURE;
        }

        $enApp     = $estado['is_on_biz_app'];
        $plataforma = $estado['platform_type'];

        $this->line("  is_on_biz_app: <fg=cyan>" . ($enApp ? 'true' : 'false') . "</>");
        $this->line("  platform_type: <fg=cyan>{$plataforma}</>");
        $this->newLine();

        if (! $enApp || $plataforma !== 'CLOUD_API') {
            $this->warn('Este número NO está en coexistencia.');
            $this->line('Para conectarlo, desde el teléfono: WhatsApp Business → Ajustes → Cuenta →');
            $this->line('Plataforma de Business → Conectar. Requiere la app en versión 2.24.17 o superior.');

            return self::FAILURE;
        }

        $this->info('El número está en coexistencia.');

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        // A partir de aquí sí se consumen intentos: se avisa y se pide confirmación.
        $this->newLine();
        $this->warn('La sincronización solo se puede pedir UNA VEZ por número.');
        $this->line('Si falla o llega incompleta, hay que desvincular el número desde la app y');
        $this->line('volver a conectarlo para poder reintentar. Además, hay 24 horas de plazo');
        $this->line('desde que se conectó.');
        $this->newLine();

        if (! $this->confirm('¿Continuar?', false)) {
            $this->line('Cancelado. No se consumió ningún intento.');

            return self::SUCCESS;
        }

        $only = $this->option('only');
        $ok   = true;

        // Los contactos van primero: así los mensajes del historial ya tienen
        // a quién asociarse cuando lleguen.
        if ($only === null || $only === 'contacts') {
            $ok = $this->sincronizar($whatsapp, 'smb_app_state_sync', 'contactos', $phoneNumberId) && $ok;
        }

        if ($only === null || $only === 'history') {
            $ok = $this->sincronizar($whatsapp, 'history', 'historial de chats', $phoneNumberId) && $ok;
        }

        $this->newLine();
        $this->comment('Los datos llegan por webhook y en lotes: puede tardar varios minutos.');
        $this->line('Seguilo con:  tail -f storage/logs/laravel.log | grep -i "coexistencia\\|smb_app\\|history"');
        $this->newLine();
        $this->line('Pedile al dueño del número que deje la app de WhatsApp Business abierta:');
        $this->line('acelera la transferencia.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function sincronizar(
        WhatsAppService $whatsapp,
        string $syncType,
        string $etiqueta,
        ?string $phoneNumberId,
    ): bool {
        $this->line("Pidiendo {$etiqueta}...");

        $resultado = $whatsapp->syncSmbAppData($syncType, $phoneNumberId);

        if (! $resultado['success']) {
            $this->error("  Falló: " . json_encode($resultado['error'], JSON_UNESCAPED_SLASHES));

            return false;
        }

        $this->info("  Solicitado. request_id: {$resultado['request_id']}");

        return true;
    }
}
