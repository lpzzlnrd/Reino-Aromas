<?php

namespace App\Http\Requests;

use App\Models\InstagramCommentSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInstagramCommentSettingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],

            'public_reply_active' => ['sometimes', 'boolean'],

            // 280 y no más: Instagram recorta los comentarios largos, y este es
            // un aviso de una línea, no el mensaje.
            'public_reply_text' => ['nullable', 'string', 'max:280'],

            'response_type' => [
                'sometimes',
                Rule::in([
                    InstagramCommentSetting::RESPONSE_TEMPLATE,
                    InstagramCommentSetting::RESPONSE_TEXT,
                ]),
            ],

            'template_id' => ['nullable', 'integer', 'exists:templates,id'],

            // 900 y no 1000: el DM lo manda Meta como mensaje normal y los
            // textos larguísimos se cortan en el cliente. Un saludo de
            // bienvenida no necesita más.
            'response_text' => ['nullable', 'string', 'max:900'],

            'keywords'   => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:60'],

            // 0 = sin tope. El máximo alto es deliberado: el negocio puede
            // querer una campaña grande, pero no un número imposible que
            // delate un dedazo.
            'daily_limit' => ['sometimes', 'integer', 'min:0', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $config = InstagramCommentSetting::actual();

            // El tipo puede no venir en un PATCH parcial: se toma el guardado
            // para validar contra el estado real y no contra un default.
            $tipo = $this->input('response_type', $config->response_type);

            if ($tipo === InstagramCommentSetting::RESPONSE_TEXT) {
                $texto = $this->input('response_text', $config->response_text);

                if ($texto === null || trim((string) $texto) === '') {
                    $validator->errors()->add(
                        'response_text',
                        'Escribe el mensaje que se enviará por privado.',
                    );
                }
            }

            if ($tipo === InstagramCommentSetting::RESPONSE_TEMPLATE) {
                $plantilla = $this->input('template_id', $config->template_id);

                if ($plantilla === null) {
                    $validator->errors()->add(
                        'template_id',
                        'Elige la plantilla que se enviará por privado.',
                    );
                }
            }

            // Lo mismo para el aviso público: encendido y sin texto publicaría
            // un comentario vacío en nombre del negocio, debajo de su propio
            // post y a la vista de todos.
            $avisoActivo = $this->boolean('public_reply_active', $config->public_reply_active);

            if ($avisoActivo) {
                $avisoTexto = $this->input('public_reply_text', $config->public_reply_text);

                if ($avisoTexto === null || trim((string) $avisoTexto) === '') {
                    $validator->errors()->add(
                        'public_reply_text',
                        'Escribe el comentario que se publicará debajo del suyo.',
                    );
                }
            }

            // Activar sin nada que enviar dejaría la automatización encendida y
            // muda: los comentarios se marcarían como omitidos y el negocio
            // creería que Meta no está enviando los webhooks.
            $activa = $this->boolean('is_active', $config->is_active);

            if ($activa && $validator->errors()->isEmpty()) {
                $simulada = $config->replicate()->forceFill([
                    'response_type' => $tipo,
                    'response_text' => $this->input('response_text', $config->response_text),
                    'template_id'   => $this->input('template_id', $config->template_id),
                ]);

                // setRelation y no un save: la relación cargada es la que lee
                // respuesta(), y acá todavía no hay nada guardado.
                $simulada->setRelation(
                    'template',
                    $simulada->template_id !== null
                        ? \App\Models\Template::find($simulada->template_id)
                        : null,
                );

                if ($simulada->respuesta() === null) {
                    $validator->errors()->add(
                        'is_active',
                        'No se puede activar sin un mensaje válido: revisa el texto o que la plantilla esté activa.',
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
            'keywords.max'     => 'Como máximo 20 palabras clave.',
            'keywords.*.max'   => 'Cada palabra clave puede tener hasta 60 caracteres.',
            'daily_limit.max'  => 'El tope diario no puede pasar de 5000.',
            'response_text.max' => 'El mensaje no puede pasar de 900 caracteres.',
            'public_reply_text.max' => 'El comentario público no puede pasar de 280 caracteres.',
        ];
    }
}
