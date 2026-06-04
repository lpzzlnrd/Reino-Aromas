<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class TemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name'      => 'Precio Caracas',
                'body'      => "¡Hola {{nombre}}! 🕯️ Te comparto nuestros precios en Caracas:\n\n• Curso de Velas: $130\n• Curso de Jabones: $130\n• Curso de Difusores: $130\n\nIncluye materiales e insumos para practicar. ¿Te interesa alguno?",
                'city'      => 'caracas',
                'is_active' => true,
            ],
            [
                'name'      => 'Precio Valencia',
                'body'      => "¡Hola {{nombre}}! 🌿 Nuestros precios en Valencia:\n\n• Curso de Velas: $110\n• Curso de Jabones: $110\n• Curso de Sales Aromáticas: $110\n\n¿Te gustaría conocer más detalles?",
                'city'      => 'valencia',
                'is_active' => true,
            ],
            [
                'name'      => 'Precio Barquisimeto',
                'body'      => "¡Hola {{nombre}}! ✨ Estos son nuestros precios en Barquisimeto:\n\n• Curso de Velas: $110\n• Curso de Jabones: $110\n\n¿Cuál te llama más la atención?",
                'city'      => 'barquisimeto',
                'is_active' => true,
            ],
            [
                'name'      => 'Precio Margarita',
                'body'      => "¡Hola {{nombre}}! 🌺 Precios en Margarita:\n\n• Curso de Velas: $250\n• Curso de Jabones: $250\n• Experiencia VIP artesanal incluida.\n\n¿Te interesa reservar tu lugar?",
                'city'      => 'margarita',
                'is_active' => true,
            ],
            [
                'name'      => 'Métodos de pago',
                'body'      => "¡Hola {{nombre}}! Aceptamos:\n\n💳 Zelle / PayPal (USD)\n📲 Pago Móvil (Bs)\n🏦 Transferencia bancaria\n\n¿Con cuál te queda más cómodo?",
                'city'      => null,
                'is_active' => true,
            ],
            [
                'name'      => 'Confirmación de reserva',
                'body'      => "¡Perfecto, {{nombre}}! Tu lugar en el curso de {{curso}} en {{ciudad}} está reservado. 🎉\n\nTe escribimos con los detalles de la fecha y la ubicación. ¡Nos vemos pronto!",
                'city'      => null,
                'is_active' => true,
            ],
        ];

        foreach ($templates as $tpl) {
            Template::firstOrCreate(['name' => $tpl['name']], $tpl);
        }
    }
}
