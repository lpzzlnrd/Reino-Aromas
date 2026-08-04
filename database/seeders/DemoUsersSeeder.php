<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $agents = [
            [
                'name'     => 'María González',
                'email'    => 'maria@reinoaromas.com',
                'role'     => 'administrador',
                'is_active'=> true,
            ],
            [
                'name'     => 'Juan Pérez',
                'email'    => 'juan@reinoaromas.com',
                'role'     => 'administrador',
                'is_active'=> true,
            ],
            [
                'name'     => 'Ana Medina',
                'email'    => 'ana@reinoaromas.com',
                'role'     => 'administrador',
                'is_active'=> false,
            ],
        ];

        foreach ($agents as $agent) {
            User::firstOrCreate(
                ['email' => $agent['email']],
                array_merge($agent, ['password' => Hash::make('demo1234')])
            );
        }
    }
}
