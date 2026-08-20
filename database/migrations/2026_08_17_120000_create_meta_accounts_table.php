<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_accounts', function (Blueprint $table): void {
            $table->id();

            // Una fila por canal: este CRM vincula una sola cuenta de
            // negocio por canal.
            $table->enum('channel', ['whatsapp', 'instagram', 'facebook'])->unique();

            $table->string('display_name')->nullable();

            // Ids que llegan por el postMessage del Embedded Signup (o del
            // callback OAuth clásico en el caso de Facebook).
            $table->string('external_id', 64)->nullable();
            $table->string('waba_id', 64)->nullable();

            // Cifrado en reposo: un token con permisos de mensajería
            // permite escribir a los clientes en nombre del negocio.
            // Rotar APP_KEY deja estos tokens ilegibles a propósito.
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->enum('status', ['connected', 'disconnected', 'error'])
                ->default('disconnected');
            $table->string('error_message')->nullable();

            $table->foreignId('connected_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('verified_at')->nullable();

            // Payload crudo del postMessage/exchange, para depurar sin
            // tener que reproducir el flujo completo en Meta.
            $table->json('meta_payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_accounts');
    }
};
