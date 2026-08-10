<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Se ignora a sí misma en el unique: si no, guardar sin cambiar el
            // nombre fallaría con "ya existe".
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('templates', 'name')->ignore($this->route('template')),
            ],
            'body'      => ['required', 'string', 'max:4000'],
            'city'      => ['nullable', 'in:caracas,valencia,barquisimeto,maracay,margarita'],
            'channel'   => ['nullable', 'in:whatsapp,instagram,facebook'],
            'category'  => ['nullable', 'string', 'max:60'],
            'is_active' => ['boolean'],
            'meta_template_name' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique'  => 'Ya existe una plantilla con ese nombre.',
            'body.max'     => 'El texto no puede superar los 4000 caracteres.',
            'meta_template_name.regex' => 'Solo se permiten minúsculas, números y guion bajo (formato de Meta).',
        ];
    }
}
