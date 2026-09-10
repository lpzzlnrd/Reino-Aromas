<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de los DM de bienvenida enviados a quien comenta un post.
 *
 * Existe SOLO para garantizar idempotencia, y por eso es una tabla y no un
 * flag: Meta permite exactamente UN mensaje privado por comentario, y reintenta
 * el webhook cuando no recibe un 200 a tiempo. Sin un registro con índice
 * único, el mismo comentario generaría dos DM y el segundo sería rechazado por
 * la API — pero el cliente ya habría recibido el primero dos veces si el
 * rechazo llegara tarde.
 *
 * El `comment_id` es la clave natural del asunto: es el destinatario real de la
 * llamada (`recipient.comment_id`) y no cambia entre reintentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_comment_replies', function (Blueprint $table): void {
            $table->id();

            // El id del comentario que disparó el DM. UNIQUE es el corazón de
            // la tabla: es lo que impide el segundo envío.
            $table->string('comment_id', 191)->unique();

            // El id del post. Solo informativo: permite ver qué publicación
            // trae leads sin tener que pedirlo a la Graph API después.
            $table->string('media_id', 191)->nullable()->index();

            // IGSID de quien comentó. Nullable porque el webhook de comentarios
            // no siempre trae `from` — en cuentas con restricciones de
            // privacidad Meta lo omite, y en ese caso no se puede mandar el DM
            // pero SÍ hay que registrar el intento para no reintentarlo.
            $table->string('commenter_igsid', 191)->nullable()->index();

            // El @ de quien comentó, para poder leer la tabla sin cruzarla.
            $table->string('commenter_username', 191)->nullable();

            // El texto del comentario. Sirve para auditar por qué se respondió
            // y para afinar los filtros más adelante.
            $table->text('comment_text')->nullable();

            // En qué terminó el intento.
            //
            //   sent      → el DM salió
            //   skipped   → deliberadamente no se envió (ver skip_reason)
            //   failed    → Meta rechazó el envío
            $table->enum('status', ['sent', 'skipped', 'failed'])->default('sent');

            // Por qué se omitió o falló. Sin esto, un "skipped" es un misterio
            // y el dev termina releyendo el código para adivinarlo.
            $table->string('skip_reason', 255)->nullable();

            // El mensaje del CRM que se creó, si se envió. nullOnDelete: perder
            // el mensaje no debe borrar la marca de idempotencia, o el próximo
            // webhook volvería a enviar.
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->timestamps();

            // La consulta del panel: los últimos intentos primero.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_comment_replies');
    }
};
