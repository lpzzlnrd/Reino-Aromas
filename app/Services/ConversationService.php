<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Conversation;

class ConversationService
{
    /**
     * Recupera la conversación abierta del contacto o abre una nueva.
     */
    public function getOrOpenActive(Contact $contact): Conversation
    {
        $conversation = $contact->conversations()
            ->where('status', 'open')
            ->latest()
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return $contact->conversations()->create([
            'status'             => 'open',
            'within_24h_window'  => true,
        ]);
    }

    /**
     * Actualiza last_message_at al momento actual.
     */
    public function updateLastMessageAt(Conversation $conversation): void
    {
        $conversation->update(['last_message_at' => now()]);
    }

    /**
     * Recalcula si la ventana de 24h sigue abierta basándose en el último mensaje inbound.
     */
    public function refreshWindowStatus(Conversation $conversation): void
    {
        $lastInbound = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->first();

        $within = $lastInbound && $lastInbound->created_at->diffInHours(now()) < 24;

        $conversation->update(['within_24h_window' => $within]);
    }
}
