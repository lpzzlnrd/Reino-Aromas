<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends MetaBaseController
{
    public function index(): JsonResponse
    {
        $users = User::select('id', 'name', 'email', 'role', 'is_active', 'avatar_url', 'last_login_at')
            // Antes usaba FIELD(), que es exclusivo de MySQL: en SQLite (donde
            // corren los tests) la consulta revienta. ordenPor() genera el CASE
            // equivalente, que es SQL estándar.
            ->orderByRaw($this->ordenPor('role', User::roles()))
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return response()->json($user, 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json($user->fresh());
    }

    public function toggleActive(User $user): JsonResponse
    {
        $user->update(['is_active' => ! $user->is_active]);

        return response()->json(['is_active' => $user->is_active]);
    }
}
