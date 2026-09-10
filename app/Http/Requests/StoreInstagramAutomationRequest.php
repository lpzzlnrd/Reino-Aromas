<?php

namespace App\Http\Requests;

use App\Models\InstagramAutomation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInstagramAutomationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('automation')?->id;

        return [
            'kind' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                Rule::in([
                    InstagramAutomation::KIND_ICE_BREAKER,
                    InstagramAutomation::KIND_MENU_ITEM,
                ]),
            ],

            'title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:80'],

            // El payload viaja a Meta y vuelve en el webhook. Se restringe a
            // mayúsculas, números y guión bajo para que no haya sorpresas de
            // codificación al ida y vuelta.
            'payload' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'max:120',
                'regex:/^[A-Z0-9_]+$/',
                Rule::unique('instagram_automations', 'payload')->ignore($id),
            ],

            'response_type' => [
                'sometimes',
                Rule::in([
                    InstagramAutomation::RESPONSE_TEMPLATE,
                    InstagramAutomation::RESPONSE_TEXT,
                    InstagramAutomation::RESPONSE_HANDOFF,
                ]),
            ],

            'template_id'   => ['nullable', 'integer', 'exists:templates,id'],
            'response_text' => ['nullable', 'string', 'max:900'],
            'url'           => ['nullable', 'url', 'max:500'],
            'position'      => ['sometimes', 'integer', 'min:0', 'max:99'],
            'is_active'     => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tipo = $this->input('response_type', InstagramAutomation::RESPONSE_TEMPLATE);

            // Un botón de tipo plantilla sin plantilla, o de tipo texto sin
            // texto, se guardaría "bien" y luego no responderia nada. Mejor
            // rechazarlo acá que descubrirlo cuando un cliente lo toque.
            if ($tipo === InstagramAutomation::RESPONSE_TEMPLATE && ! $this->filled('template_id')) {
                $validator->errors()->add('template_id', 'Elegí la plantilla con la que se va a responder.');
            }

            if ($tipo === InstagramAutomation::RESPONSE_TEXT && ! $this->filled('response_text')) {
                $validator->errors()->add('response_text', 'Escribí el texto de la respuesta.');
            }

            // Meta rechaza el quinto Ice Breaker. Se corta acá para que el
            // error sea entendible en vez de un 400 de la Graph API.
            if ($this->esNuevoDelTipo(InstagramAutomation::KIND_ICE_BREAKER)) {
                $activos = InstagramAutomation::query()
                    ->active()
                    ->ofKind(InstagramAutomation::KIND_ICE_BREAKER)
                    ->count();

                if ($activos >= InstagramAutomation::MAX_ICE_BREAKERS) {
                    $validator->errors()->add(
                        'kind',
                        'Instagram permite solo ' . InstagramAutomation::MAX_ICE_BREAKERS
                        . ' preguntas frecuentes. Desactivá una para agregar otra.',
                    );
                }
            }

            // Los enlaces solo existen en el menú: un Ice Breaker siempre es
            // una pregunta que dispara un postback.
            if ($this->filled('url') && $this->input('kind') === InstagramAutomation::KIND_ICE_BREAKER) {
                $validator->errors()->add('url', 'Las preguntas frecuentes no admiten enlaces, solo respuestas.');
            }
        });
    }

    private function esNuevoDelTipo(string $kind): bool
    {
        return $this->isMethod('POST')
            && $this->input('kind') === $kind
            && $this->boolean('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payload.regex'  => 'El identificador solo admite MAYÚSCULAS, números y guión bajo (ej: CURSO_CARACAS).',
            'payload.unique' => 'Ya existe otro botón con ese identificador.',
            'title.max'      => 'El título es muy largo: Instagram lo recorta en el teléfono.',
        ];
    }
}
