<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `external_id` era VARCHAR(120), dimensionado para los `wamid` de WhatsApp
 * (~60 caracteres) y los `mid` de Messenger. Los `mid` de Instagram son
 * base64 y miden ~150:
 *
 *   aWdfZAG1faXRlbToxOklHTWVzc2FnZAUlEOjE3ODQxNDA3ODQ0MjIwOTQ5OjM0MDI4...
 *
 * Resultado: cada DM de Instagram moría con "Data too long for column
 * 'external_id'", el job se reintentaba 3 veces y el mensaje se perdía. La
 * conversación sí quedaba creada, así que en el CRM aparecía un chat vacío.
 *
 * 255 y no TEXT porque la columna tiene índice UNIQUE — es la defensa contra
 * procesar dos veces el mismo webhook — y un TEXT no se puede indexar sin
 * prefijo. 255 caracteres en utf8mb4 son 1020 bytes, dentro del límite de
 * 3072 de InnoDB, y dejan margen sobre los ~150 que gasta Instagram hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('external_id', 255)->nullable()->change();

            // media_url es VARCHAR(255) y guarda URLs de la CDN de Meta, las
            // mismas que ya reventaron profile_picture_url con ~470
            // caracteres. Todavia no ha fallado solo porque no ha entrado
            // multimedia por Instagram; se ensancha ahora en vez de esperar el
            // primer audio. Aqui si cabe TEXT: no se indexa ni se filtra.
            $table->text('media_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Los ids de Instagram no caben en 120: se vacían antes de encoger, o
        // MySQL los truncaría y dos mensajes distintos podrían colisionar en el
        // índice unique.
        DB::table('messages')
            ->whereRaw('CHAR_LENGTH(external_id) > 120')
            ->update(['external_id' => null]);

        DB::table('messages')
            ->whereRaw('CHAR_LENGTH(media_url) > 255')
            ->update(['media_url' => null]);

        Schema::table('messages', function (Blueprint $table): void {
            $table->string('external_id', 120)->nullable()->change();
            $table->string('media_url')->nullable()->change();
        });
    }
};
