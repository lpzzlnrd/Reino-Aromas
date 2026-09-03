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

        // El nombre se respeta si un agente ya lo edito a mano, pero un
        // contacto guardado SIN nombre si se corrige cuando la API por fin
        // responde: los de Instagram se crearon con display_name null hasta que
        // se implemento la User Profile API, y sin esto se quedarian asi para
        // siempre.
        if (!empty($profileData['display_name']) && ($isNew || $contact->display_name === null)) {
            $contact->display_name = $profileData['display_name'];
        }

        if (!empty($profileData['profile_picture_url'])) {
            $contact->profile_picture_url = $profileData['profile_picture_url'];
        }

        // El @ de Instagram: es el identificador con el que la gente se
        // reconoce en esa red, y la ficha de cliente ya tiene un campo para el.
        if (!empty($profileData['instagram_handle'])) {
            $contact->instagram_handle = $profileData['instagram_handle'];
        }

        $contact->save();

        return $contact;
    }
}
