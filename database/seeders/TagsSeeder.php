<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagsSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['name' => 'Interesado velas',    'color' => '#F59E0B'],
            ['name' => 'Interesado jabones',  'color' => '#8B5CF6'],
            ['name' => 'Reserva pendiente',   'color' => '#EF4444'],
            ['name' => 'Pago confirmado',      'color' => '#10B981'],
            ['name' => 'Requiere seguimiento', 'color' => '#3B82F6'],
            ['name' => 'VIP',                  'color' => '#C9922A'],
            ['name' => 'Primera compra',       'color' => '#EC4899'],
            ['name' => 'Maracay TBD',          'color' => '#6B7280'],
        ];

        foreach ($tags as $tag) {
            Tag::firstOrCreate(['name' => $tag['name']], $tag);
        }
    }
}
