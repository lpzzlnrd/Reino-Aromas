<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Envío de una plantilla a una conversación.
 *
 * Sin reglas de cuerpo a propósito: el texto se renderiza en el servidor desde
 * la plantilla, no llega en el request. La plantilla y la conversación se
 * resuelven por route binding.
 */
class StoreTemplateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
