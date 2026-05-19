<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabla de contactos: representa a cada cliente que escribe por Instagram, WhatsApp o Facebook.
// Un contacto se crea automáticamente la primera vez que alguien manda un mensaje al CRM.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            // Canal por el que llegó el contacto al CRM.
            $table->enum('channel', ['whatsapp', 'instagram', 'facebook']);

            // ID único del contacto dentro de su plataforma.
            // Para Instagram es el IGSID; para WhatsApp, el número en formato E.164.
            $table->string('channel_id', 120);

            // Nombre visible del contacto (viene del perfil de la plataforma, puede ser null).
            $table->string('display_name', 160)->nullable();

            // Foto de perfil traída desde Meta (URL temporal, puede expirar).
            $table->string('profile_picture_url')->nullable();

            // Ciudad del contacto. Se llena manualmente por el agente o se infiere del contexto.
            $table->enum('city', ['caracas', 'valencia', 'barquisimeto', 'maracay', 'margarita'])->nullable();

            // Teléfono normalizado en formato E.164 (ej: +584121234567). Solo aplica para WhatsApp.
            $table->string('phone', 40)->nullable();

            // Handle de Instagram sin el @. Se guarda si está disponible en el perfil.
            $table->string('instagram_handle', 80)->nullable();

            // Cuándo llegó este contacto al CRM por primera vez.
            $table->timestamp('first_seen_at');

            // Cuándo fue la última vez que este contacto interactuó con el CRM.
            $table->timestamp('last_seen_at');

            $table->timestamps();
            $table->softDeletes();

            // Garantiza que no se dupliquen contactos del mismo canal.
            // Un mismo número de WhatsApp o IGSID solo puede existir una vez.
            $table->unique(['channel', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
