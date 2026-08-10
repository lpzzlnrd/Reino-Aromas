<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de conversaciones.
 *
 * Reemplaza los closures que vivían en routes/api.php (GET /meta/chats y
 * GET /meta/conversations/{id}). Además del orden, el cambio corrige el mapeo
 * de estados: el closure comparaba los status del ticket contra valores en
 * inglés ('interested', 'high_priority') cuando la columna los guarda en
 * español, así que TODOS los chats se mostraban como "Nuevo". Ahora la
 * traducción vive en Ticket::statusLabels().
 */
class ConversationController extends MetaBaseController
{
    /**
     * GET /api/meta/chats
     *
     * Lista de conversaciones para la bandeja, con el último mensaje de cada
     * una. Consumido por useMetaData.ts → loadMetaChats().
     *
     * Filtros opcionales por query string:
     *   status  → open | closed | all   (default: open)
     *   channel → whatsapp | instagram | facebook
     *   case    → uno de los status de ticket (nuevo, interesado…)
     *   search  → nombre del contacto o teléfono
     *   mine    → 1 para ver solo los tickets asignados al usuario actual
     */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', Conversation::STATUS_OPEN);

        $conversations = Conversation::query()
            ->when(
                $status !== 'all',
                fn ($query) => $query->where('status', $status),
            )
            ->when(
                $request->filled('channel'),
                fn ($query) => $query->whereHas(
                    'contact',
                    fn ($q) => $q->where('channel', $request->query('channel')),
                ),
            )
            ->when(
                $request->filled('case'),
                fn ($query) => $query->whereHas(
                    'ticket',
                    fn ($q) => $q->where('status', $request->query('case')),
                ),
            )
            ->when(
                $request->boolean('mine') && $request->user() !== null,
                fn ($query) => $query->whereHas(
                    'ticket',
                    fn ($q) => $q->where('assigned_user_id', $request->user()->id),
                ),
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = (string) $request->query('search');

                return $query->whereHas('contact', function ($q) use ($search): void {
                    $q->where('display_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('instagram_handle', 'like', "%{$search}%");
                });
            })
            ->with([
                'contact',
                // Solo el último mensaje: cargar la historia completa de cada
                // chat para mostrar una línea de preview no escala.
                'messages' => fn ($q) => $q->latest('created_at')->limit(1),
                'ticket',
            ])
            ->orderByDesc('last_message_at')
            ->get();

        return response()->json(
            $conversations->map(fn (Conversation $conv): array => $this->serializarResumen($conv))
        );
    }

    /**
     * GET /api/meta/conversations/{conversation}
     *
     * Detalle completo: contacto, ticket e historial de mensajes.
     */
    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load([
            'contact',
            'messages.sender:id,name,avatar_url',
            'ticket.assignedUser:id,name,avatar_url',
            'ticket.tags:id,name,color',
        ]);

        return response()->json([
            'id'                => $conversation->id,
            'status'            => $conversation->status,
            'within_24h_window' => $conversation->within_24h_window,
            'last_message_at'   => $conversation->last_message_at?->toIso8601String(),
            'contact'           => $this->serializarContacto($conversation->contact),
            'ticket'            => $this->serializarTicket($conversation->ticket),
            'messages'          => $conversation->messages
                ->map(fn (Message $msg): array => $this->serializarMensaje($msg))
                ->values(),
        ]);
    }

    /**
     * PATCH /api/meta/conversations/{conversation}/close
     *
     * Cierra la conversación sin tocar el ticket: son ciclos de vida distintos
     * — el chat puede cerrarse mientras el ticket sigue en seguimiento.
     */
    public function close(Conversation $conversation): JsonResponse
    {
        $conversation->update(['status' => Conversation::STATUS_CLOSED]);

        return $this->jsonSuccess([
            'id'     => $conversation->id,
            'status' => $conversation->status,
        ]);
    }

    /**
     * PATCH /api/meta/conversations/{conversation}/reopen
     */
    public function reopen(Conversation $conversation): JsonResponse
    {
        $conversation->update(['status' => Conversation::STATUS_OPEN]);

        return $this->jsonSuccess([
            'id'     => $conversation->id,
            'status' => $conversation->status,
        ]);
    }

    /**
     * Forma que espera MetaApiChat en useMetaData.ts.
     *
     * @return array<string, mixed>
     */
    private function serializarResumen(Conversation $conv): array
    {
        return [
            'id'             => $conv->id,
            'contact_name'   => $conv->contact?->display_name ?? 'Sin nombre',
            'contact_avatar' => $conv->contact?->profile_picture_url,
            'last_message'   => $conv->messages->first()?->body ?? '',
            'message_time'   => $conv->last_message_at?->toIso8601String(),
            'location'       => $conv->contact?->city,
            // Etiqueta del enum CaseStatus.ts. 'Nuevo' cuando no hay ticket.
            'case_status'    => $conv->ticket?->statusLabel() ?? 'Nuevo',
            'channel'        => $conv->contact?->channel,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializarContacto(?Contact $contact): ?array
    {
        if ($contact === null) {
            return null;
        }

        return [
            'id'                  => $contact->id,
            'display_name'        => $contact->display_name,
            'profile_picture_url' => $contact->profile_picture_url,
            'channel'             => $contact->channel,
            'channel_id'          => $contact->channel_id,
            'city'                => $contact->city,
            'phone'               => $contact->phone,
            'instagram_handle'    => $contact->instagram_handle,
            'first_seen_at'       => $contact->first_seen_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializarTicket(?Ticket $ticket): ?array
    {
        if ($ticket === null) {
            return null;
        }

        return [
            'id'              => $ticket->id,
            'status'          => $ticket->status,
            // El status crudo es para los <select> del panel; la etiqueta es la
            // que pinta el badge de color.
            'status_label'    => $ticket->statusLabel(),
            'priority'        => $ticket->priority,
            'city'            => $ticket->city,
            'course_interest' => $ticket->course_interest,
            'notes'           => $ticket->notes,
            'assigned_user'   => $ticket->assignedUser ? [
                'id'     => $ticket->assignedUser->id,
                'name'   => $ticket->assignedUser->name,
                'avatar' => $ticket->assignedUser->avatar_url,
            ] : null,
            'tags'            => $ticket->tags->map(fn ($tag): array => [
                'id'    => $tag->id,
                'name'  => $tag->name,
                'color' => $tag->color,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializarMensaje(Message $msg): array
    {
        return [
            'id'            => $msg->id,
            'direction'     => $msg->direction,
            'channel'       => $msg->channel,
            'type'          => $msg->type,
            'body'          => $msg->body,
            'media_url'     => $msg->media_url,
            'status'        => $msg->status,
            // El front lo necesita para mostrar por qué falló un envío.
            'failed_reason' => $msg->failed_reason,
            'sender'        => $msg->sender ? [
                'id'     => $msg->sender->id,
                'name'   => $msg->sender->name,
                'avatar' => $msg->sender->avatar_url,
            ] : null,
            'sent_at'      => $msg->sent_at?->toIso8601String(),
            'delivered_at' => $msg->delivered_at?->toIso8601String(),
            'read_at'      => $msg->read_at?->toIso8601String(),
            'created_at'   => $msg->created_at->toIso8601String(),
        ];
    }
}
