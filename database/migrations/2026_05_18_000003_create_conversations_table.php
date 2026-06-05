<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabla de conversaciones: agrupa todos los mensajes de un contacto en un hilo.
// Un contacto puede tener varias conversaciones a lo largo del tiempo (una por período de actividad).
// La conversación activa es siempre la que tiene status = 'open'.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            // Contacto dueño de esta conversación.
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            // Estado del hilo. 'open' = activo, 'closed' = archivado.
            $table->enum('status', ['open', 'closed'])->default('open');

            // Fecha del último mensaje (entrante o saliente).
            // Se usa para ordenar la bandeja de chats por actividad reciente.
            $table->timestamp('last_message_at')->nullable();

            // Indica si la ventana de 24h de Meta está abierta.
            // Si es true, el agente puede enviar texto libre.
            // Si es false, solo se pueden enviar plantillas aprobadas por Meta.
            $table->boolean('within_24h_window')->default(false);

            $table->timestamps();

            // Índice compuesto para filtrar conversaciones abiertas de un contacto rápidamente.
            $table->index(['contact_id', 'status']);

            // Índice para ordenar la bandeja por actividad reciente sin escanear toda la tabla.
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
