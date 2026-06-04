<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:120'],
            'email'                 => ['required', 'email', 'max:160', 'unique:users,email'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'role'                  => ['required', 'in:superadmin,administrador'],
            'is_active'             => ['boolean'],
        ];
    }
}
