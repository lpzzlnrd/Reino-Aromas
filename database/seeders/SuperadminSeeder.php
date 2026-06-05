<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// Crea el usuario Superadmin inicial del sistema.
// Este seeder se puede correr en producción sin riesgo: usa firstOrCreate,
// así que si el superadmin ya existe no lo duplica ni lo sobreescribe.
class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            // Busca por email
            ['email' => 'admin@reinoaromas.com'],
            // Si no existe, lo crea con estos datos
            [
                'name'      => 'Superadmin',
                'password'  => Hash::make('reinoaromas2024'),
                'role'      => 'superadmin',
                'is_active' => true,
            ]
        );

        $this->command->info('✅ Superadmin listo: admin@reinoaromas.com / reinoaromas2024');
        $this->command->warn('⚠️  Cambia la contraseña después del primer login.');
    }
}
