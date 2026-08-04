<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoConversationsAndTicketsSeeder extends Seeder
{
    public function run(): void
    {
        $agents = User::where('role', '!=', 'superadmin')->get();
        $tags   = Tag::all()->keyBy('name');

        $scenarios = [
            [
                'contact_channel' => 'whatsapp', 'contact_id' => '+584121000001',
                'ticket_status'   => 'alta_prioridad', 'priority' => 'muy_alta',
                'course_interest' => 'Velas',
                'messages' => [
                    ['direction' => 'inbound',  'body' => '¡Hola! Vi el curso de velas en Instagram, me interesa mucho 🕯️'],
                    ['direction' => 'outbound', 'body' => '¡Hola Carmen! Claro que sí, te cuento todos los detalles del curso.'],
                    ['direction' => 'inbound',  'body' => '¿Cuánto cuesta y cuándo es la próxima fecha?'],
                    ['direction' => 'outbound', 'body' => 'El curso en Caracas tiene un valor de $130 e incluye todos los materiales. ¿Cuándo puedes?'],
                    ['direction' => 'inbound',  'body' => 'Perfecto! Me parece bien. Quiero reservar mi lugar ya.'],
                ],
                'tag_names' => ['Interesado velas', 'Reserva pendiente'],
            ],
            [
                'contact_channel' => 'whatsapp', 'contact_id' => '+584121000002',
                'ticket_status'   => 'interesado', 'priority' => 'alta',
                'course_interest' => 'Jabones',
                'messages' => [
                    ['direction' => 'inbound',  'body' => 'Buenas! ¿Tienen cursos de jabones naturales?'],
                    ['direction' => 'outbound', 'body' => '¡Sí! Tenemos el curso de Jabones Artesanales. ¿Estás en Caracas?'],
                    ['direction' => 'inbound',  'body' => 'Sí, en Las Mercedes. ¿Cuál es el precio?'],
                ],
                'tag_names' => ['Interesado jabones'],
            ],
            [
                'contact_channel' => 'instagram', 'contact_id' => 'ig_100001',
                'ticket_status'   => 'en_seguimiento', 'priority' => 'media',
                'course_interest' => 'Difusores',
                'messages' => [
                    ['direction' => 'inbound',  'body' => 'Hola! Vi su publicación de difusores, quiero saber más 🌸'],
                    ['direction' => 'outbound', 'body' => '¡Hola Valentina! Con gusto te informamos sobre el curso de Difusores Aromáticos.'],
                    ['direction' => 'inbound',  'body' => 'Qué bueno! ¿Incluye materiales?'],
                    ['direction' => 'outbound', 'body' => 'Sí, incluye todos los materiales para preparar 3 difusores durante la clase.'],
                ],
                'tag_names' => ['Requiere seguimiento'],
            ],
            [
                'contact_channel' => 'whatsapp', 'contact_id' => '+584141000001',
                'ticket_status'   => 'reservado', 'priority' => 'media',
                'course_interest' => 'Velas',
                'messages' => [
                    ['direction' => 'inbound',  'body' => 'Buenos días, quiero reservar el curso de velas en Valencia'],
                    ['direction' => 'outbound', 'body' => '¡Buenos días Carlos! Con gusto. El curso en Valencia es $110.'],
                    ['direction' => 'inbound',  'body' => 'Ok, voy a pagar por Zelle. ¿A qué dirección?'],
                    ['direction' => 'outbound', 'body' => 'Perfecto, te envío los datos de pago por este mismo chat.'],
                    ['direction' => 'inbound',  'body' => 'Listo, ya hice el pago! 🎉'],
                ],
                'tag_names' => ['Pago confirmado'],
            ],
            [
                'contact_channel' => 'whatsapp', 'contact_id' => '+584261000001',
                'ticket_status'   => 'nuevo', 'priority' => 'baja',
                'course_interest' => null,
                'messages' => [
                    ['direction' => 'inbound',  'body' => 'Hola, ¿tienen cursos en Barquisimeto?'],
                ],
                'tag_names' => [],
            ],
            [
                'contact_channel' => 'whatsapp', 'contact_id' => '+584951000001',
                'ticket_status'   => 'alta_prioridad', 'priority' => 'alta',
                'course_interest' => 'Sales Aromáticas',
                'messages' => [
                    ['direction' => 'inbound',  'body' => 'Hola! Estoy en Margarita y me interesa el curso de sales aromáticas 🌊'],
                    ['direction' => 'outbound', 'body' => '¡Hola Nathalie! Qué emocionante, el curso en Margarita es una experiencia muy especial.'],
                    ['direction' => 'inbound',  'body' => '¿Cuánto cuesta?'],
                    ['direction' => 'outbound', 'body' => 'El valor en Margarita es de $250 e incluye una experiencia VIP artesanal.'],
                    ['direction' => 'inbound',  'body' => 'Me interesa, cuándo hay cupos?'],
                ],
                'tag_names' => ['VIP', 'Requiere seguimiento'],
            ],
            [
                'contact_channel' => 'whatsapp', 'contact_id' => '+584121000003',
                'ticket_status'   => 'cerrado', 'priority' => 'baja',
                'course_interest' => 'Mantequilla corporal',
                'messages' => [
                    ['direction' => 'inbound',  'body' => 'Hola, ¿tienen curso de mantequilla corporal?'],
                    ['direction' => 'outbound', 'body' => '¡Sí Gabriela! Tenemos el curso de Mantequilla Corporal Artesanal.'],
                    ['direction' => 'inbound',  'body' => 'Excelente, ya lo hice el mes pasado, fue increíble ✨'],
                    ['direction' => 'outbound', 'body' => '¡Qué alegría que te gustara! Recuerda que tenemos nuevos cursos disponibles.'],
                ],
                'tag_names' => ['Pago confirmado'],
            ],
        ];

        foreach ($scenarios as $s) {
            $contact = Contact::where('channel', $s['contact_channel'])
                ->where('channel_id', $s['contact_id'])
                ->first();

            if (! $contact) {
                continue;
            }

            $isClosed   = $s['ticket_status'] === 'cerrado';
            $conv = Conversation::firstOrCreate(
                ['contact_id' => $contact->id],
                [
                    'status'             => $isClosed ? 'closed' : 'open',
                    'within_24h_window'  => ! $isClosed,
                    'last_message_at'    => now()->subMinutes(rand(2, 120)),
                ]
            );

            $agent = $agents->isNotEmpty() ? $agents->random() : null;

            $ticket = Ticket::firstOrCreate(
                ['conversation_id' => $conv->id],
                [
                    'assigned_user_id'   => $agent?->id,
                    'created_by_user_id' => $agent?->id,
                    'status'             => $s['ticket_status'],
                    'priority'           => $s['priority'],
                    'city'               => $contact->city,
                    'course_interest'    => $s['course_interest'],
                    'closed_at'          => $isClosed ? now()->subDays(rand(1, 30)) : null,
                ]
            );

            // Mensajes demo
            if ($conv->messages()->count() === 0) {
                $baseTime = now()->subMinutes(count($s['messages']) * 5 + rand(30, 180));
                foreach ($s['messages'] as $i => $msg) {
                    Message::create([
                        'conversation_id' => $conv->id,
                        'sender_user_id'  => $msg['direction'] === 'outbound' ? $agent?->id : null,
                        'direction'       => $msg['direction'],
                        'channel'         => $contact->channel,
                        'type'            => 'text',
                        'body'            => $msg['body'],
                        'status'          => $msg['direction'] === 'outbound' ? 'delivered' : 'read',
                        'created_at'      => $baseTime->addMinutes($i * 5),
                    ]);
                }
            }

            // Tags
            if (! empty($s['tag_names'])) {
                $tagIds = collect($s['tag_names'])
                    ->map(fn($name) => $tags->get($name)?->id)
                    ->filter()
                    ->all();
                $ticket->tags()->syncWithoutDetaching($tagIds);
            }
        }
    }
}
