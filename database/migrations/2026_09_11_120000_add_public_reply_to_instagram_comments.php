<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respuesta PUBLICA debajo del comentario, además del DM privado.
 *
 * Sin el aviso público la persona no se entera de que tiene un privado
 * esperando: si no sigue la cuenta, el DM cae en la carpeta de Solicitudes, que
 * nadie mira. Y el resto de la gente que lee los comentarios tampoco ve que la
 * cuenta responde.
 *
 * Va como dos campos aparte y no reutilizando `response_text` porque son
 * mensajes de naturaleza distinta: el privado es largo y comercial, el público
 * es una línea corta ("te escribimos al DM") que se ve debajo del post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_comment_settings', function (Blueprint $table): void {
            // Independiente del DM: se puede querer el aviso público sin el
            // privado, o al revés. Apagado por defecto, como todo lo que habla
            // en nombre del negocio sin que nadie lo haya revisado.
            $table->boolean('public_reply_active')->default(false)->after('is_active');

            // Corto a propósito: Instagram recorta los comentarios largos y
            // este es un aviso, no el mensaje.
            $table->string('public_reply_text', 280)->nullable()->after('public_reply_active');
        });

        Schema::table('instagram_comment_replies', function (Blueprint $table): void {
            // El id del comentario que publicamos. Sirve de marca de
            // idempotencia igual que el DM: si Meta reintenta el webhook, un
            // valor acá dice que el aviso ya se publicó y no hay que duplicarlo
            // debajo del post.
            $table->string('public_reply_id', 191)->nullable()->after('message_id');

            // Por qué no se publicó el aviso, cuando el DM sí salió. Son dos
            // envíos independientes y cada uno puede fallar por su cuenta.
            $table->string('public_reply_error', 255)->nullable()->after('public_reply_id');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_comment_settings', function (Blueprint $table): void {
            $table->dropColumn(['public_reply_active', 'public_reply_text']);
        });

        Schema::table('instagram_comment_replies', function (Blueprint $table): void {
            $table->dropColumn(['public_reply_id', 'public_reply_error']);
        });
    }
};
