<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Cualquier usuario autenticado y activo puede responder un chat: el
        // equipo son 3 agentes que atienden la misma bandeja compartida.
        return (bool) $this->user()?->is_active;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // 4096 es el límite de texto de la API de WhatsApp, el más bajo de
            // los tres canales.
            'body' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'El mensaje no puede estar vacío.',
            'body.max'      => 'El mensaje no puede exceder 4096 caracteres.',
        ];
    }
}
