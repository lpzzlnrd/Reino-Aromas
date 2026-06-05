<?php

namespace App\Services;

use App\Models\Contact;

class ContactService
{
    /**
     * Busca un contacto por (canal, id externo) y lo crea si no existe.
     * Actualiza last_seen_at y datos de perfil en cada llamada.
     */
    public function findOrCreate(string $channel, string $channelId, array $profileData = []): Contact
    {
        $contact = Contact::firstOrNew(
            ['channel' => $channel, 'channel_id' => $channelId]
        );

        $isNew = !$contact->exists;

        if ($isNew) {
            $contact->first_seen_at = now();
        }

        $contact->last_seen_at = now();

        if (!empty($profileData['display_name']) && $isNew) {
            $contact->display_name = $profileData['display_name'];
        }

        if (!empty($profileData['profile_picture_url'])) {
            $contact->profile_picture_url = $profileData['profile_picture_url'];
        }

        $contact->save();

        return $contact;
    }
}
