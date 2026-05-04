<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('status', ['nuevo', 'interesado', 'alta_prioridad', 'en_seguimiento', 'reservado', 'cerrado'])->default('nuevo');
            $table->enum('priority', ['baja', 'media', 'alta', 'muy_alta'])->default('media');
            $table->enum('city', ['caracas', 'valencia', 'barquisimeto', 'maracay', 'margarita'])->nullable();
            $table->string('course_interest', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority', 'assigned_user_id']);
            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
