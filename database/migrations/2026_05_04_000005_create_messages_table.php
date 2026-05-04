<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('channel', ['whatsapp', 'instagram', 'facebook']);
            $table->string('external_id', 120)->nullable();
            $table->enum('type', ['text', 'image', 'audio', 'video', 'document', 'template', 'system']);
            $table->text('body')->nullable();
            $table->string('media_url')->nullable();
            $table->string('media_path')->nullable();
            $table->json('meta_payload')->nullable();
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->string('failed_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'created_at']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
