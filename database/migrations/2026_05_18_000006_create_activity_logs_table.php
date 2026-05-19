<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabla de auditoría centralizada. Registra cada acción relevante del sistema:
// asignaciones, cambios de estado, cambios de prioridad, mensajes enviados, etc.
// Solo tiene created_at porque los logs nunca se modifican, solo se agregan.
// El Superadmin usa esta tabla para ver el historial completo de cualquier ticket.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Quién realizó la acción. Polimórfico: puede ser un User u otro modelo en el futuro.
            // Null si la acción la realizó el sistema automáticamente (ej: ticket creado por webhook).
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->string('causer_type')->nullable(); // ej: 'App\Models\User'

            // Sobre qué entidad se realizó la acción. Polimórfico: Ticket, Conversation, User...
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_type')->nullable(); // ej: 'App\Models\Ticket'

            // Nombre de la acción en formato punto. Ej: 'ticket.assigned', 'ticket.status_changed'.
            $table->string('action', 80);

            // Datos extra de la acción en JSON. Ej: {"from": "nuevo", "to": "interesado"}.
            $table->json('metadata')->nullable();

            // Solo created_at. Los logs son inmutables.
            $table->timestamp('created_at')->useCurrent();

            // Índice para cargar el historial de una entidad específica (ej: todos los logs de un ticket).
            $table->index(['target_type', 'target_id', 'created_at']);

            // Índice para ver todas las acciones que realizó un agente específico.
            $table->index(['causer_type', 'causer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
