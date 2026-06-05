<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabla de mensajes: almacena cada mensaje individual de una conversación.
// Incluye mensajes entrantes (del cliente) y salientes (del agente).
// Es INMUTABLE: una vez creado, un mensaje nunca se edita. Por eso no tiene updated_at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            // Conversación a la que pertenece este mensaje.
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            // Agente que envió el mensaje. Null si es un mensaje entrante del cliente.
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // Dirección del mensaje: 'inbound' = cliente → CRM, 'outbound' = CRM → cliente.
            $table->enum('direction', ['inbound', 'outbound']);

            // Canal por el que viajó este mensaje específico.
            $table->enum('channel', ['whatsapp', 'instagram', 'facebook']);

            // ID único del mensaje en Meta. Se usa para evitar procesar el mismo webhook dos veces.
            // Unique a nivel de BD: si Meta reenvía el mismo evento, lo detectamos y lo ignoramos.
            $table->string('external_id', 120)->nullable()->unique();

            // Tipo de contenido del mensaje.
            $table->enum('type', ['text', 'image', 'audio', 'video', 'document', 'template', 'system'])->default('text');

            // Texto del mensaje. Null si es un mensaje de media (imagen, audio, etc.).
            $table->text('body')->nullable();

            // URL temporal del archivo multimedia en los servidores de Meta.
            // Estas URLs expiran, así que si se necesita persistir el archivo hay que descargarlo.
            $table->string('media_url')->nullable();

            // Path local si el archivo fue descargado al servidor del CRM.
            $table->string('media_path')->nullable();

            // Payload JSON original que envió Meta. Solo para debugging y auditoría técnica.
            $table->json('meta_payload')->nullable();

            // Estado del mensaje saliente en el ciclo de vida de entrega.
            // pending → sent → delivered → read. Si falla: failed.
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('pending');

            // Razón del fallo si status = 'failed'. Guarda el código de error de Meta.
            $table->string('failed_reason')->nullable();

            // Timestamps específicos del ciclo de entrega (equivalen a los ticks de WhatsApp).
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // Solo created_at. Sin updated_at porque los mensajes no se modifican.
            $table->timestamp('created_at')->useCurrent();

            // Índice para cargar el hilo de mensajes de una conversación ordenado por fecha.
            $table->index(['conversation_id', 'created_at']);

            // Índice para la detección de duplicados al procesar webhooks.
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
