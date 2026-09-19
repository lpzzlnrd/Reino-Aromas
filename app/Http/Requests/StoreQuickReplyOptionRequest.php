<?php

namespace App\Http\Requests;

use App\Models\InstagramAutomation;
use App\Models\InstagramQuickReplyMenu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreQuickReplyOptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 20 caracteres es el tope de Meta para el título de una burbuja.
            // Pasarse no falla: Meta lo recorta con puntos suspensivos en el
            // móvil, que es peor porque el admin no se entera.
            'title' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'max:' . InstagramQuickReplyMenu::MAX_TITULO_OPCION,
            ],

            'response_type' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                Rule::in([
                    InstagramAutomation::RESPONSE_TEMPLATE,
                    InstagramAutomation::RESPONSE_TEXT,
                    InstagramAutomation::RESPONSE_HANDOFF,
                ]),
            ],

            'template_id'   => ['nullable', 'integer', 'exists:templates,id'],
            'response_text' => ['nullable', 'string', 'max:900'],
            'position'      => ['sometimes', 'integer', 'min:0', 'max:99'],
            'is_active'     => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tipo = $this->input('response_type', InstagramAutomation::RESPONSE_TEMPLATE);

            // Mismo criterio que StoreInstagramAutomationRequest: una opción de
            // tipo plantilla sin plantilla se guardaría "bien" y no respondería
            // nada cuando un cliente la toque.
            if ($tipo === InstagramAutomation::RESPONSE_TEMPLATE && ! $this->filled('template_id')) {
                $validator->errors()->add('template_id', 'Elige la plantilla con la que se va a responder.');
            }

            if ($tipo === InstagramAutomation::RESPONSE_TEXT && ! $this->filled('response_text')) {
                $validator->errors()->add('response_text', 'Escribe el texto con el que se va a responder.');
            }

            // El tope de 13 lo impone Meta y es duro: la burbuja 14 hace que se
            // rechace el mensaje ENTERO, no que se recorte. Solo se comprueba al
            // crear — al editar no cambia la cantidad.
            if ($this->isMethod('POST')) {
                $menu = $this->route('menu');

                if ($menu instanceof InstagramQuickReplyMenu
                    && $menu->opciones()->count() >= InstagramQuickReplyMenu::MAX_OPCIONES) {
                    $validator->errors()->add(
                        'title',
                        'Instagram permite hasta ' . InstagramQuickReplyMenu::MAX_OPCIONES
                        . ' opciones por menú.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Escribe lo que va a decir el botón.',
            'title.max'      => 'Instagram recorta los títulos de más de '
                . InstagramQuickReplyMenu::MAX_TITULO_OPCION . ' caracteres.',
        ];
    }
}
