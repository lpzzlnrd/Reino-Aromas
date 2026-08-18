<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\OutboundMessageService;
use Illuminate\Console\Command;

/**
 * Reintenta en bloque los mensajes salientes que fallaron.
 *
 * Para qué sirve: cuando Meta tiene una caída, un token caduca o el VPS pierde
 * red, TODOS los envíos de esa ventana quedan en 'failed'. Reintentarlos uno a
 * uno desde el chat no es viable, y hasta ahora no había alternativa.
 *
 * No se agenda solo en el scheduler a propósito: un reintento automático de algo
 * que falla por una razón permanente (un número que no existe, un contacto que
 * bloqueó al negocio) repetiría el error para siempre. El dev decide cuándo
 * corresponde, normalmente después de arreglar la causa.
 */
class RetryFailedMessagesCommand extends Command
{
    protected $signature = 'messages:retry-failed
                            {--limit=50 : Máximo de mensajes a reintentar}
                            {--channel= : Solo un canal (whatsapp, instagram, facebook)}
                            {--since= : Solo los fallidos desde esta fecha (ej: 2026-08-18)}
                            {--dry-run : Muestra qué se reintentaría sin encolar nada}';

    protected $description = 'Vuelve a encolar los mensajes salientes que quedaron en failed';

    public function handle(OutboundMessageService $outbound): int
    {
        $limite = (int) $this->option('limit');
        $canal  = $this->option('channel');
        $desde  = $this->option('since');
        $simular = (bool) $this->option('dry-run');

        $query = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('status', Message::STATUS_FAILED)
            ->with('conversation.contact');

        if (is_string($canal) && $canal !== '') {
            $query->where('channel', $canal);
        }

        if (is_string($desde) && $desde !== '') {
            $query->where('created_at', '>=', $desde);
        }

        // Los más antiguos primero: si hubo una caída, conviene reenviar en el
        // orden en que el agente los escribió.
        $mensajes = $query->oldest('created_at')->limit($limite)->get();

        if ($mensajes->isEmpty()) {
            $this->info('No hay mensajes fallidos que reintentar.');

            return self::SUCCESS;
        }

        $this->info("Mensajes fallidos encontrados: {$mensajes->count()}");

        if ($simular) {
            $this->table(
                ['ID', 'Canal', 'Contacto', 'Razón del fallo'],
                $mensajes->map(fn (Message $m): array => [
                    $m->id,
                    $m->channel,
                    $m->conversation?->contact?->display_name ?? '—',
                    // La razón puede ser un JSON largo de Meta; se recorta para
                    // que la tabla siga siendo legible.
                    mb_strimwidth((string) $m->failed_reason, 0, 60, '...'),
                ])->all(),
            );

            $this->warn('Simulación: no se encoló nada. Quitá --dry-run para reintentar.');

            return self::SUCCESS;
        }

        $reintentados = 0;
        $omitidos     = 0;

        foreach ($mensajes as $mensaje) {
            try {
                $outbound->retryFailedMessage($mensaje);
                $reintentados++;
            } catch (\RuntimeException $e) {
                // Un mensaje sin contacto o de un canal no soportado no se puede
                // reintentar nunca. Se informa y se sigue con el resto: un caso
                // roto no debe detener la recuperación de los demás.
                $this->warn("  #{$mensaje->id} omitido: {$e->getMessage()}");
                $omitidos++;
            }
        }

        $this->newLine();
        $this->info("Reencolados: {$reintentados}");

        if ($omitidos > 0) {
            $this->warn("Omitidos: {$omitidos} (revisar el contacto o el canal)");
        }

        $this->line('El envío real ocurre en la cola: seguilo con journalctl -u reino-queue -f');

        return self::SUCCESS;
    }
}
