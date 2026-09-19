<?php

namespace App\Http\Requests;

use App\Services\Meta\InstagramService;
use Illuminate\Foundation\Http\FormRequest;

class StoreInstagramQuickReplyMenuRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],

            // El tope es el de un DM de Instagram, no un número inventado: el
            // cuerpo del menú se envía como el texto del mensaje y pasarse
            // hace que Meta rechace el envío entero (error 2534038).
            'body' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'max:' . InstagramService::MAX_CARACTERES_DM,
            ],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre para reconocerlo en la lista.',
            'body.required' => 'Escribe el mensaje que acompaña a las opciones.',
            'body.max'      => 'Instagram solo permite ' . InstagramService::MAX_CARACTERES_DM
                . ' caracteres por mensaje.',
        ];
    }
}
