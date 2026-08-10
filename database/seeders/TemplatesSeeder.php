<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class TemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // Los campos price/deposit/includes/visit_frequency/schedule los
            // consume el endpoint de WhatsApp Flows para armar la pantalla de
            // información del curso. Son la fuente de verdad del precio: el
            // texto del body es solo para copiar y pegar en un chat.
            [
                'name'      => 'Precio Caracas',
                'body'      => "¡Hola {{nombre}}! 🕯️ Te comparto nuestros precios en Caracas:\n\n• Curso de Velas: $130\n• Curso de Jabones: $130\n• Curso de Difusores: $130\n\nIncluye materiales e insumos para practicar. ¿Te interesa alguno?",
                'city'      => 'caracas',
                'is_active' => true,
                'price'           => 130.00,
                'deposit'         => 20.00,
                'includes'        => 'Materiales e insumos, desayuno, refrigerio y café.',
                'visit_frequency' => 'Todas las semanas',
                'schedule'        => '10:00 am a 6:00 pm',
            ],
            [
                'name'      => 'Precio Valencia',
                'body'      => "¡Hola {{nombre}}! 🌿 Nuestros precios en Valencia:\n\n• Curso de Velas: $110\n• Curso de Jabones: $110\n• Curso de Sales Aromáticas: $110\n\n¿Te gustaría conocer más detalles?",
                'city'      => 'valencia',
                'is_active' => true,
                'price'           => 110.00,
                'deposit'         => 20.00,
                'includes'        => 'Materiales e insumos, desayuno, refrigerio y café.',
                'visit_frequency' => 'Cada mes',
                'schedule'        => '10:00 am a 6:00 pm',
            ],
            [
                'name'      => 'Precio Barquisimeto',
                'body'      => "¡Hola {{nombre}}! ✨ Estos son nuestros precios en Barquisimeto:\n\n• Curso de Velas: $110\n• Curso de Jabones: $110\n\n¿Cuál te llama más la atención?",
                'city'      => 'barquisimeto',
                'is_active' => true,
                'price'           => 110.00,
                'deposit'         => 20.00,
                'includes'        => 'Materiales e insumos, desayuno, refrigerio y café.',
                'visit_frequency' => 'Cada 2 meses',
                'schedule'        => '10:00 am a 6:00 pm',
            ],
            [
                // Margarita está "en desarrollo" según el cliente. Se deja
                // inactiva a propósito: el Flow lista solo ciudades activas
                // con precio, así que no aparecerá hasta que la activen.
                'name'      => 'Precio Margarita',
                'body'      => "¡Hola {{nombre}}! 🌺 Precios en Margarita:\n\n• Curso de Velas: $250\n• Curso de Jabones: $250\n• Experiencia VIP artesanal incluida.\n\n¿Te interesa reservar tu lugar?",
                'city'      => 'margarita',
                'is_active' => false,
                'price'           => 250.00,
                'deposit'         => 20.00,
                'includes'        => 'Materiales e insumos, desayuno, refrigerio, café y experiencia VIP.',
                'visit_frequency' => 'En desarrollo',
                'schedule'        => '10:00 am a 6:00 pm',
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

        // updateOrCreate y no firstOrCreate: al agregar campos nuevos (price,
        // includes, etc.) firstOrCreate dejaría las plantillas ya existentes
        // sin poblar, porque solo escribe cuando crea.
        foreach ($templates as $tpl) {
            Template::updateOrCreate(['name' => $tpl['name']], $tpl);
        }
    }
}
