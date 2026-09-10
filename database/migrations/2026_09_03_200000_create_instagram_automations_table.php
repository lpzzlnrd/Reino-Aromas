<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Botones automáticos de Instagram: Ice Breakers y Persistent Menu.
 *
 * Instagram no tiene WhatsApp Flows. Lo más parecido son estos dos mecanismos,
 * que se configuran por API (`/me/messenger_profile` en graph.instagram.com) y
 * disparan un webhook `messaging_postbacks` cuando alguien los toca.
 *
 * Una sola tabla para los dos porque comparten forma exacta —título, payload,
 * orden— y se sincronizan contra el mismo endpoint. Separarlas duplicaría el
 * CRUD y la vista sin ganar nada.
 *
 * El `payload` es la pieza clave: es el string que Meta devuelve en el webhook,
 * y es lo que permite saber qué botón se tocó para responder en consecuencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_automations', function (Blueprint $table): void {
            $table->id();

            // ice_breaker: pregunta frecuente ANTES de que escriban (máx. 4).
            // menu_item:   entrada del menú siempre visible (recomendado máx. 5).
            $table->enum('kind', ['ice_breaker', 'menu_item']);

            // Lo que ve el usuario. Meta recorta los títulos largos en el
            // cliente móvil, de ahí el límite corto.
            $table->string('title', 80);

            // El identificador que vuelve en el webhook. Único para poder
            // resolverlo sin ambigüedad al responder.
            $table->string('payload', 120)->unique();

            // Qué responder cuando lo tocan. Las tres opciones son excluyentes
            // y se valida en el Request, no aquí: un CHECK constraint sobre
            // esto sería difícil de leer y de cambiar.
            //
            //   template: responde con una plantilla de `templates`
            //   text:     responde con un texto fijo
            //   handoff:  no responde nada, solo marca el ticket para un agente
            $table->enum('response_type', ['template', 'text', 'handoff'])->default('template');

            // Plantilla a enviar si response_type = template. nullOnDelete y no
            // cascade: borrar una plantilla no debe borrar el botón, solo
            // dejarlo sin respuesta configurada para que se vea en la UI.
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();

            // Texto a enviar si response_type = text.
            $table->text('response_text')->nullable();

            // Solo para menu_item de tipo enlace. Si viene, en Meta se manda
            // como botón web_url en vez de postback.
            $table->string('url', 500)->nullable();

            // Orden en que Meta los muestra.
            $table->unsignedSmallInteger('position')->default(0);

            // Desactivar sin borrar: así se prueba una variante y se vuelve
            // atrás sin perder la configuración.
            $table->boolean('is_active')->default(true);

            // Cuándo se envió por última vez a Meta. Sirve para que la UI
            // avise "hay cambios sin sincronizar": la config local y la de Meta
            // son dos fuentes de verdad distintas y pueden divergir.
            $table->timestamp('synced_at')->nullable();

            // Veces que se tocó. Es la métrica que dice qué botón sirve y cuál
            // sobra, y Meta no la expone.
            $table->unsignedInteger('hits')->default(0);

            $table->timestamps();

            $table->index(['kind', 'is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_automations');
    }
};
