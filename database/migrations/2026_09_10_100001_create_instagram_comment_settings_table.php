<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración del DM automático a quien comenta un post.
 *
 * Tabla aparte de `instagram_automations` a propósito: eso es una LISTA de
 * botones y esto es una única configuración. Meterlo ahí obligaría a ampliar el
 * enum `kind` y a que la mitad de las columnas (payload, position, url) no
 * aplicaran, con una fila que no es un botón conviviendo con los que sí lo son.
 *
 * Es una tabla de una sola fila. Se prefirió a un archivo de config porque el
 * texto lo edita alguien de negocio desde el CRM, y a un `settings` genérico
 * clave-valor porque con columnas tipadas la validación y los defaults viven en
 * un solo sitio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_comment_settings', function (Blueprint $table): void {
            $table->id();

            // Apagado por defecto: la tabla se crea en el deploy y nadie quiere
            // que empiece a mandar DM a 25k seguidores sin haber revisado el
            // texto primero.
            $table->boolean('is_active')->default(false);

            // Qué responder. Mismas tres opciones que los botones, por
            // coherencia, MENOS handoff: aquí no hay nada que derivar porque el
            // contacto todavía no escribió nada. Si no se quiere responder, se
            // desactiva y punto.
            $table->enum('response_type', ['template', 'text'])->default('text');

            // Plantilla a enviar si response_type = template. nullOnDelete y no
            // cascade: borrar la plantilla no debe borrar la configuración, solo
            // dejarla sin respuesta para que la UI lo muestre.
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();

            // Texto a enviar si response_type = text.
            $table->text('response_text')->nullable();

            // Solo responder a comentarios que contengan alguna de estas
            // palabras (JSON, una lista de strings). Vacío = responder a todos.
            //
            // Es el filtro que pide el negocio cuando una campaña dice
            // "comenta PRECIO": sin él, un "que lindo" también recibiría el DM
            // con la lista de precios.
            $table->json('keywords')->nullable();

            // Tope de DM por día. Un post que se mueve puede traer cientos de
            // comentarios en una hora, y Meta penaliza el envío masivo — además
            // de que el negocio no puede atender esa avalancha.
            //
            // 0 = sin tope.
            $table->unsignedInteger('daily_limit')->default(100);

            $table->timestamps();
        });

        // La fila única, con el texto por defecto ya puesto: así la vista tiene
        // algo que mostrar desde el primer día y nadie ve una pantalla vacía
        // sin saber si cargó mal.
        DB::table('instagram_comment_settings')->insert([
            'is_active'     => false,
            'response_type' => 'text',
            'response_text' => '¡Hola! Gracias por comentar en nuestra publicación 🌿 '
                . 'Somos Reino Aromas. ¿Te cuento sobre nuestros cursos artesanales?',
            'daily_limit'   => 100,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_comment_settings');
    }
};
