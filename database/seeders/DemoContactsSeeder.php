<?php

namespace Database\Seeders;

use App\Models\Contact;
use Illuminate\Database\Seeder;

class DemoContactsSeeder extends Seeder
{
    public function run(): void
    {
        $contacts = [
            // Caracas — WhatsApp (mayor volumen)
            ['channel' => 'whatsapp', 'channel_id' => '+584121000001', 'display_name' => 'Carmen Silva',      'city' => 'caracas',      'phone' => '+584121000001'],
            ['channel' => 'whatsapp', 'channel_id' => '+584121000002', 'display_name' => 'Luis Hernández',    'city' => 'caracas',      'phone' => '+584121000002'],
            ['channel' => 'whatsapp', 'channel_id' => '+584121000003', 'display_name' => 'Gabriela Torres',   'city' => 'caracas',      'phone' => '+584121000003'],
            ['channel' => 'whatsapp', 'channel_id' => '+584121000004', 'display_name' => 'Roberto Díaz',      'city' => 'caracas',      'phone' => '+584121000004'],
            ['channel' => 'whatsapp', 'channel_id' => '+584121000005', 'display_name' => 'Patricia Ramírez',  'city' => 'caracas',      'phone' => '+584121000005'],
            // Caracas — Instagram
            ['channel' => 'instagram', 'channel_id' => 'ig_100001', 'display_name' => 'Valentina Morales', 'city' => 'caracas', 'instagram_handle' => '@vale_aromas'],
            ['channel' => 'instagram', 'channel_id' => 'ig_100002', 'display_name' => 'Sofía Castillo',    'city' => 'caracas', 'instagram_handle' => '@sofi_velas'],
            // Valencia — WhatsApp
            ['channel' => 'whatsapp', 'channel_id' => '+584141000001', 'display_name' => 'Carlos Rodríguez',  'city' => 'valencia',     'phone' => '+584141000001'],
            ['channel' => 'whatsapp', 'channel_id' => '+584141000002', 'display_name' => 'Andreína López',    'city' => 'valencia',     'phone' => '+584141000002'],
            ['channel' => 'instagram', 'channel_id' => 'ig_200001', 'display_name' => 'Miguel Vargas',      'city' => 'valencia', 'instagram_handle' => '@miguelv_vlc'],
            // Barquisimeto — WhatsApp
            ['channel' => 'whatsapp', 'channel_id' => '+584261000001', 'display_name' => 'Adriana Gutiérrez', 'city' => 'barquisimeto', 'phone' => '+584261000001'],
            ['channel' => 'whatsapp', 'channel_id' => '+584261000002', 'display_name' => 'José Martínez',     'city' => 'barquisimeto', 'phone' => '+584261000002'],
            // Maracay — WhatsApp
            ['channel' => 'whatsapp', 'channel_id' => '+584431000001', 'display_name' => 'Daniela Flores',    'city' => 'maracay',      'phone' => '+584431000001'],
            // Margarita — WhatsApp
            ['channel' => 'whatsapp', 'channel_id' => '+584951000001', 'display_name' => 'Nathalie Ramos',    'city' => 'margarita',    'phone' => '+584951000001'],
            ['channel' => 'whatsapp', 'channel_id' => '+584951000002', 'display_name' => 'Eduardo Peñaloza',  'city' => 'margarita',    'phone' => '+584951000002'],
        ];

        foreach ($contacts as $c) {
            Contact::firstOrCreate(
                ['channel' => $c['channel'], 'channel_id' => $c['channel_id']],
                array_merge($c, [
                    'first_seen_at' => now()->subDays(rand(1, 90)),
                    'last_seen_at'  => now()->subMinutes(rand(5, 1440)),
                ])
            );
        }
    }
}
