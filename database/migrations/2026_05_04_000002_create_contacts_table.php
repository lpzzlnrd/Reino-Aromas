<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->enum('channel', ['whatsapp', 'instagram', 'facebook']);
            $table->string('channel_id', 120);
            $table->string('display_name', 160)->nullable();
            $table->string('profile_picture_url')->nullable();
            $table->enum('city', ['caracas', 'valencia', 'barquisimeto', 'maracay', 'margarita'])->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('instagram_handle', 80)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['channel', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
