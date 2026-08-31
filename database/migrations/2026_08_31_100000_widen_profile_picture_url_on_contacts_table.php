<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `profile_picture_url` era VARCHAR(255) y las URLs firmadas de la CDN de Meta
 * miden ~470 caracteres: `_nc_ohc`, `_nc_oc`, `oh` y `oe` se comen 300+ ellos
 * solos. Nunca iban a caber.
 *
 * El daño no era cosmético. En ProcessMetaWebhookJob el save() del contacto
 * vive dentro de una transacción, así que el truncado hacía rollback y el
 * Message::create() de más abajo no llegaba a ejecutarse: se perdía el mensaje
 * entero por culpa de una foto de perfil.
 *
 * TEXT y no VARCHAR(2048) porque una URL firmada no tiene tope documentado por
 * Meta, y la columna no se indexa ni se filtra: no hay nada que ganar acotando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->text('profile_picture_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volver a 255 truncaría las URLs largas ya guardadas. Se vacían las que
        // no caben en vez de dejar que MySQL corte a ciegas y deje una URL rota
        // que parece válida.
        \DB::table('contacts')
            ->whereRaw('CHAR_LENGTH(profile_picture_url) > 255')
            ->update(['profile_picture_url' => null]);

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('profile_picture_url')->nullable()->change();
        });
    }
};
