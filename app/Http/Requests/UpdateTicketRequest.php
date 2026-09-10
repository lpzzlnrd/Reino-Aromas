<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Contact;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Todos opcionales: el front hace ediciones parciales (cambiar solo
            // la ciudad, solo las notas…) desde el panel lateral del chat.
            'status'           => ['sometimes', Rule::in(Ticket::statuses())],
            'priority'         => ['sometimes', Rule::in(Ticket::priorities())],
            'city'             => ['sometimes', 'nullable', Rule::in(self::cities())],
            // Estado del pais, distinto de la sede: un cliente de Tachira
            // puede atenderse desde la sede de Valencia. El catalogo vive en
            // Contact::states() porque la columna es VARCHAR, no ENUM.
            'state'            => ['sometimes', 'nullable', Rule::in(Contact::states())],
            'course_interest'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes'            => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * Ciudades del enum de la migración de tickets.
     *
     * Margarita se incluye porque la columna la acepta; que su plantilla esté
     * inactiva en el catálogo de cursos es una decisión de negocio aparte.
     *
     * @return list<string>
     */
    private static function cities(): array
    {
        return ['caracas', 'valencia', 'barquisimeto', 'maracay', 'margarita'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in'              => 'El estado del ticket no es válido.',
            'priority.in'            => 'La prioridad no es válida.',
            'city.in'                => 'La ciudad no está entre las sedes disponibles.',
            'state.in'               => 'El estado no está entre los estados de Venezuela.',
            'assigned_user_id.exists' => 'El usuario asignado no existe.',
        ];
    }
}
