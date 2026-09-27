<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InstagramDmSetting;
use App\Models\InstagramQuickReplyMenu;
use App\Models\Template;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validación de la respuesta automática al DM.
 *
 * Es casi la misma que la del DM por comentario, con dos campos propios
 * (`trigger_mode` y `skip_if_assigned`) y el mismo cuidado al activar: ver
 * withValidator().
 */
class UpdateInstagramDmSettingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],

            'response_type' => [
                'sometimes',
                Rule::in([
                    InstagramDmSetting::RESPONSE_TEMPLATE,
                    InstagramDmSetting::RESPONSE_TEXT,
                    InstagramDmSetting::RESPONSE_MENU,
                ]),
            ],

            'template_id' => ['nullable', 'integer', 'exists:templates,id'],

            'quick_reply_menu_id' => [
                'nullable',
                'integer',
                'exists:instagram_quick_reply_menus,id',
            ],

            // Mismo tope que el DM por comentario: Meta corta los textos
            // largos en el cliente y esto es un saludo, no un catálogo.
            'response_text' => ['nullable', 'string', 'max:900'],

            'keywords'   => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:60'],

            'trigger_mode' => [
                'sometimes',
                Rule::in([
                    InstagramDmSetting::TRIGGER_FIRST,
                    InstagramDmSetting::TRIGGER_ALWAYS,
                ]),
            ],

            'skip_if_assigned' => ['sometimes', 'boolean'],

            // Hasta 24h de silencio. Más que eso es, en la práctica,
            // "responder una vez por persona", y para eso está trigger_mode.
            'cooldown_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],

            'daily_limit' => ['sometimes', 'integer', 'min:0', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $config = InstagramDmSetting::actual();

            $tipo = $this->input('response_type', $config->response_type);

            // Activar sin nada que enviar dejaría la automatización encendida
            // y muda: los mensajes se marcarían como omitidos y el negocio
            // creería que los webhooks no están llegando.
            $activa = $this->boolean('is_active', $config->is_active);

            if (! $activa || $validator->errors()->isNotEmpty()) {
                return;
            }

            // Se simula la configuración resultante en vez de mirar los campos
            // sueltos: respuesta() ya sabe cuál importa según el tipo, y
            // repetir esa lógica acá es justo como nació el bug que hacía
            // rechazar todo menú completo con un 422.
            $simulada = $config->replicate()->forceFill([
                'response_type'       => $tipo,
                'response_text'       => $this->input('response_text', $config->response_text),
                'template_id'         => $this->input('template_id', $config->template_id),
                'quick_reply_menu_id' => $this->input(
                    'quick_reply_menu_id',
                    $config->quick_reply_menu_id,
                ),
            ]);

            // setRelation y no un save: la relación cargada es la que lee
            // respuesta(), y acá todavía no hay nada guardado.
            $simulada->setRelation(
                'template',
                $simulada->template_id !== null
                    ? Template::find($simulada->template_id)
                    : null,
            );

            // Con sus opciones: estaCompleto() las recorre y sin precargarlas
            // daría false para un menú sano.
            $simulada->setRelation(
                'quickReplyMenu',
                $simulada->quick_reply_menu_id !== null
                    ? InstagramQuickReplyMenu::with('opciones')->find($simulada->quick_reply_menu_id)
                    : null,
            );

            if ($simulada->respuesta() === null) {
                $validator->errors()->add(
                    'is_active',
                    'No se puede activar sin un mensaje válido: revisa el texto, '
                    . 'o que la plantilla o el menú estén activos y completos.',
                );
            }
        });
    }
}
