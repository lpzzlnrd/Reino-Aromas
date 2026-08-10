<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado del CRM puede gestionar plantillas: los
     * agentes son quienes mejor saben qué respuestas funcionan. El middleware
     * 'role' de la ruta ya limita el acceso a superadmin/administrador.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:120', 'unique:templates,name'],
            'body'      => ['required', 'string', 'max:4000'],
            // NULL = aplica a todas las ciudades / todos los canales.
            'city'      => ['nullable', 'in:caracas,valencia,barquisimeto,maracay,margarita'],
            'channel'   => ['nullable', 'in:whatsapp,instagram,facebook'],
            'category'  => ['nullable', 'string', 'max:60'],
            'is_active' => ['boolean'],
            // Nombre de la plantilla aprobada en Meta. Meta solo admite
            // minúsculas, números y guion bajo.
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
