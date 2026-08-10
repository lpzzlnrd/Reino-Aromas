<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contactos del CRM.
 *
 * Sin store(): los contactos nacen de los webhooks de Meta vía
 * ContactService::findOrCreate(). Crearlos a mano produciría registros sin
 * channel_id que ningún canal puede alcanzar.
 */
class ContactController extends MetaBaseController
{
    /**
     * GET /api/contacts
     *
     * Filtros: channel, city, search (nombre, teléfono o handle de Instagram).
     */
    public function index(Request $request): JsonResponse
    {
        $contacts = Contact::query()
            ->when(
                $request->filled('channel'),
                fn ($q) => $q->where('channel', $request->query('channel')),
            )
            ->when(
                $request->filled('city'),
                fn ($q) => $q->where('city', $request->query('city')),
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = (string) $request->query('search');

                return $query->where(function ($q) use ($search): void {
                    $q->where('display_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('instagram_handle', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('last_seen_at')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => collect($contacts->items())
                ->map(fn (Contact $c): array => $this->serializar($c))
                ->values(),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page'    => $contacts->lastPage(),
                'total'        => $contacts->total(),
            ],
        ]);
    }

    /**
     * GET /api/contacts/{contact}
     *
     * Incluye las conversaciones del contacto para ver su historial completo.
     */
    public function show(Contact $contact): JsonResponse
    {
        $contact->load([
            'conversations' => fn ($q) => $q->orderByDesc('last_message_at'),
            'conversations.ticket',
        ]);

        return response()->json([
            ...$this->serializar($contact),
            'conversations' => $contact->conversations->map(fn ($conv): array => [
                'id'              => $conv->id,
                'status'          => $conv->status,
                'last_message_at' => $conv->last_message_at?->toIso8601String(),
                'ticket'          => $conv->ticket ? [
                    'id'           => $conv->ticket->id,
                    'status'       => $conv->ticket->status,
                    'status_label' => $conv->ticket->statusLabel(),
                ] : null,
            ])->values(),
        ]);
    }

    /**
     * PATCH /api/contacts/{contact}
     *
     * Solo los campos que el agente corrige a mano. `channel` y `channel_id`
     * quedan fuera a propósito: los asigna Meta y cambiarlos rompería la
     * correlación de los webhooks entrantes.
     */
    public function update(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'display_name'     => ['sometimes', 'string', 'max:160'],
            'city'             => [
                'sometimes',
                'nullable',
                Rule::in(['caracas', 'valencia', 'barquisimeto', 'maracay', 'margarita']),
            ],
            'phone'            => ['sometimes', 'nullable', 'string', 'max:32'],
            'instagram_handle' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $contact->update($validated);

        return $this->jsonSuccess(['contact' => $this->serializar($contact->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(Contact $contact): array
    {
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
            'last_seen_at'        => $contact->last_seen_at?->toIso8601String(),
        ];
    }
}
