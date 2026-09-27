<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de las respuestas automáticas enviadas a quien escribe un DM.
 *
 * A diferencia de `instagram_comment_replies`, acá NO hay una clave natural
 * única que garantice idempotencia: el mismo contacto puede escribir muchas
 * veces y cada vez es un caso legítimo distinto. Lo que impide el envío
 * repetido es el cooldown, y esta tabla es lo que lo hace posible —guarda
 * cuándo se le respondió por última vez a cada IGSID.
 *
 * También sostiene el tope diario y deja auditar por qué un mensaje no recibió
 * respuesta, que es la pregunta que va a hacer el negocio en cuanto algo no
 * salga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_dm_replies', function (Blueprint $table): void {
            $table->id();

            // IGSID de quien escribió. Es la clave del cooldown: la consulta
            // caliente es "¿cuándo le respondí por última vez a éste?".
            $table->string('sender_igsid', 191)->index();

            // El @ de quien escribió, para leer la tabla sin cruzarla.
            $table->string('sender_username', 191)->nullable();

            // El texto que disparó (o no) la respuesta. Sirve para afinar las
            // palabras clave viendo mensajes reales, que es justo lo que hace
            // falta para pasar de "responder a todos" a filtrar bien.
            $table->text('incoming_text')->nullable();

            // En qué terminó el intento. Mismos tres estados que en
            // comentarios, por coherencia al leer los dos paneles.
            $table->enum('status', ['sent', 'skipped', 'failed'])->default('sent');

            // Por qué se omitió o falló.
            $table->string('skip_reason', 255)->nullable();

            // El mensaje del CRM que se creó, si se envió.
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->timestamps();

            // El tope diario cuenta los envíos de hoy; el cooldown busca el
            // último de un IGSID. Este índice compuesto sirve a los dos.
            $table->index(['sender_igsid', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_dm_replies');
    }
};
