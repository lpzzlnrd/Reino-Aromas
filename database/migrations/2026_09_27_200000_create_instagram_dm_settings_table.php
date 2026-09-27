<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de la respuesta automática a quien escribe un DM.
 *
 * Es el hermano de `instagram_comment_settings`: aquélla responde a quien
 * COMENTA un post, ésta a quien ESCRIBE al buzón. Se separan porque los cortes
 * son distintos —un comentario es público y puntual, un DM abre una
 * conversación que un agente puede estar atendiendo— y mezclarlas obligaría a
 * que cada campo dijera a cuál de los dos casos aplica.
 *
 * Tabla de una sola fila, igual que la de comentarios y por las mismas razones:
 * el texto lo edita alguien de negocio desde el CRM y las columnas tipadas
 * dejan la validación y los defaults en un solo sitio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_dm_settings', function (Blueprint $table): void {
            $table->id();

            // Apagado por defecto: nadie quiere que el CRM empiece a contestar
            // solo a toda la bandeja apenas se despliega, sin haber revisado
            // el texto.
            $table->boolean('is_active')->default(false);

            // Mismas tres formas de responder que el DM por comentario. Sin
            // handoff: "no responder" acá se consigue apagando la
            // automatización, y un handoff automático sería justamente no
            // hacer nada.
            $table->enum('response_type', ['template', 'text', 'menu'])->default('menu');

            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();

            // nullOnDelete y no cascade en las dos: borrar la plantilla o el
            // menú deja la configuración sin respuesta —y la UI lo muestra—
            // en vez de borrar la configuración entera.
            $table->foreignId('quick_reply_menu_id')
                ->nullable()
                ->constrained('instagram_quick_reply_menus')
                ->nullOnDelete();

            $table->text('response_text')->nullable();

            // Solo responder si el mensaje contiene alguna de estas palabras
            // (JSON, lista de strings). Vacío = responder a todos.
            $table->json('keywords')->nullable();

            // A quién se le responde. Es la decisión que evita que el robot
            // pise a los agentes:
            //
            // - 'first'  → solo el PRIMER mensaje de una conversación nueva.
            //              Quien ya viene hablando no recibe nada.
            // - 'always' → todo mensaje entrante, salvo los cortes de abajo.
            //
            // El default es 'first' porque es el único que no puede
            // interrumpir una conversación en curso.
            $table->enum('trigger_mode', ['first', 'always'])->default('first');

            // No responder si un agente ya tomó el ticket. Sin esto, el
            // cliente recibe el menú automático mientras una persona le está
            // escribiendo, que es la peor cara posible de la automatización.
            $table->boolean('skip_if_assigned')->default(true);

            // Minutos de silencio antes de volver a responderle al mismo
            // contacto. Evita que alguien que manda cinco mensajes seguidos
            // ("hola", "hola?", "buenas") reciba cinco menús.
            //
            // Aplica también en modo 'first': dos conversaciones abiertas y
            // cerradas el mismo día no deben disparar dos veces.
            $table->unsignedInteger('cooldown_minutes')->default(60);

            // Tope de respuestas por día. Mismo motivo que en comentarios:
            // Meta penaliza el envío masivo. 0 = sin tope.
            $table->unsignedInteger('daily_limit')->default(200);

            $table->timestamps();
        });

        // La fila única con un texto por defecto, para que la vista tenga algo
        // que mostrar desde el primer día.
        DB::table('instagram_dm_settings')->insert([
            'is_active'        => false,
            'response_type'    => 'menu',
            'response_text'    => '¡Hola! Gracias por escribirnos 🌿 Somos Reino Aromas. '
                . '¿Qué te gustaría saber?',
            'trigger_mode'     => 'first',
            'skip_if_assigned' => true,
            'cooldown_minutes' => 60,
            'daily_limit'      => 200,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_dm_settings');
    }
};
