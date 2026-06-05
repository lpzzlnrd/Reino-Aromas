<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabla de tickets: es la entidad principal de seguimiento del CRM.
// Cada conversación tiene un ticket que los agentes mueven por el Kanban.
// Guarda el estado del lead, su prioridad, a qué agente está asignado y notas internas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            // Conversación asociada. Unique: una conversación solo puede tener un ticket activo.
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();

            // Agente responsable de atender este ticket. Null = sin asignar.
            // restrictOnDelete: no se puede borrar un agente si tiene tickets asignados.
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // Agente que creó el ticket (puede ser null si lo creó el sistema automáticamente).
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // Estado actual del ticket en el Kanban.
            // nuevo → interesado → alta_prioridad / en_seguimiento → reservado → cerrado
            $table->enum('status', ['nuevo', 'interesado', 'alta_prioridad', 'en_seguimiento', 'reservado', 'cerrado'])->default('nuevo');

            // Prioridad visual del ticket. Afecta el orden en la bandeja y el color del indicador.
            $table->enum('priority', ['baja', 'media', 'alta', 'muy_alta'])->default('media');

            // Ciudad del lead. Se copia del contacto al crear el ticket para agilizar los filtros.
            $table->enum('city', ['caracas', 'valencia', 'barquisimeto', 'maracay', 'margarita'])->nullable();

            // Curso o producto de interés declarado por el cliente (ej: "Velas", "Ceras de soja").
            $table->string('course_interest', 120)->nullable();

            // Notas internas del agente. No son visibles para el cliente.
            $table->text('notes')->nullable();

            // Fecha en que el lead hizo una reserva. Se llena automáticamente al mover a 'reservado'.
            $table->timestamp('reserved_at')->nullable();

            // Fecha en que el ticket fue cerrado. Se llena automáticamente al mover a 'cerrado'.
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Índice para los filtros principales del Kanban y la bandeja por agente.
            $table->index(['status', 'priority', 'assigned_user_id']);

            // Índice adicional para cargar rápido la bandeja de un agente específico.
            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
