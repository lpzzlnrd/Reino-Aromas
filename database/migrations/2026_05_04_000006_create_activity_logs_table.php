<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('causer');
            $table->nullableMorphs('target');
            $table->string('action', 80);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['target_type', 'target_id', 'created_at']);
            $table->index(['causer_type', 'causer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
