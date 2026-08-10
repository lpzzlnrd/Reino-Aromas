<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Meta\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía el mensaje interactivo que abre el Flow de bienvenida.
 *
 * Se despacha cuando escribe un contacto nuevo. Reemplaza los primeros
 * mensajes que hoy un agente manda a mano (saludo + preguntar ciudad).
 *
 * IMPORTANTE: si este job falla, el ticket ya existe en estado 'nuevo' —
 * `ensureTicketExists()` corre antes. Un agente lo ve igual en la bandeja,
 * así que un fallo aquí degrada la experiencia pero no pierde el lead.
 */
class SendWhatsAppFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * Backoff corto a propósito: el Flow solo tiene sentido si llega en los
     * segundos siguientes al mensaje del cliente. Si tarda minutos, es peor
     * que no mandarlo — el agente ya habrá respondido a mano.
     */
    public array $backoff = [5, 15];

    public function __construct(
        private readonly int $conversationId,
        private readonly string $recipientPhone,
    ) {}

    public function handle(WhatsAppService $whatsApp): void
    {
        $flowId = (string) config('services.meta.flows.welcome_flow_id');

        if ($flowId === '') {
            // Sin Flow configurado no es un error: simplemente la función no
            // está activa todavía. El ticket ya existe y el agente atiende.
            Log::info('[Flows] FLOWS_WELCOME_ID no configurado; no se envía Flow de bienvenida.');

            return;
        }

        $conversation = Conversation::find($this->conversationId);

        if ($conversation === null) {
            Log::warning('[Flows] Conversación no encontrada al enviar el Flow', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        // Salvaguarda anti-duplicado. El job se despacha al crear el contacto,
        // pero si llegan dos mensajes casi simultáneos del mismo número (cosa
        // habitual: "hola" + "quiero info") podrían encolarse dos envíos.
        // Enviar dos Flows al mismo lead se ve como spam.
        if ($conversation->flow_sent_at !== null) {
            Log::info('[Flows] Flow ya enviado para esta conversación; se omite.', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        // El flow_token correlaciona esta sesión del Flow con la conversación:
        // vuelve en cada request al endpoint de datos, y es como el router
        // sabe a qué contacto pertenece lo que el usuario está llenando.
        $flowToken = 'conv_' . $conversation->id . '_' . bin2hex(random_bytes(8));

        $resultado = $whatsApp->sendFlowMessage(
            recipientPhone: $this->recipientPhone,
            flowId: $flowId,
            flowToken: $flowToken,
            flowCta: (string) config('services.meta.flows.welcome_cta', 'Ver cursos'),
            bodyText: (string) config(
                'services.meta.flows.welcome_body',
                '¡Hola! 🕯️ Bienvenido a Reino Aromas. Toca el botón para ver nuestros cursos y precios en tu ciudad.'
            ),
            firstScreen: (string) config('services.meta.flows.welcome_first_screen', 'BIENVENIDA'),
        );

        if (! $resultado['success']) {
            // Se deja fallar para que la cola reintente. Si agota los intentos,
            // failed() lo registra y el agente atiende el ticket a mano.
            throw new \RuntimeException(
                'Meta rechazó el mensaje de Flow: ' . json_encode($resultado['error'] ?? [])
            );
        }

        // Marcar como enviado solo tras confirmación de Meta, para que un fallo
        // no bloquee un reintento legítimo.
        $conversation->forceFill([
            'flow_sent_at'  => now(),
            'flow_token'    => $flowToken,
        ])->save();

        Log::info('[Flows] Flow de bienvenida enviado', [
            'conversation_id' => $conversation->id,
            'flow_token'      => $flowToken,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[Flows] No se pudo enviar el Flow de bienvenida', [
            'conversation_id' => $this->conversationId,
            'error'           => $exception->getMessage(),
        ]);
    }
}
