<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menús de opciones para Instagram (Quick Replies).
 *
 * Es lo más cercano que tiene Instagram a un WhatsApp Flow: un mensaje con
 * hasta 13 burbujas sobre el teclado; al tocar una, Meta dispara el mismo
 * webhook `messaging_postbacks` que ya atienden los Ice Breakers. Por eso NO
 * hace falta motor de respuesta nuevo — handlePostback() resuelve el payload
 * contra esta misma tabla y responde igual.
 *
 * ## Por qué un `kind` más y no una tabla nueva
 *
 * Una opción de menú tiene exactamente la misma forma que un Ice Breaker:
 * título, payload único, tipo de respuesta, plantilla o texto, orden, activo.
 * Una tabla aparte duplicaría el CRUD, la vista y —lo importante— la
 * resolución del postback, que tendría que buscar en dos sitios.
 *
 * ## Por qué hace falta `menu_group`
 *
 * Es la única diferencia real con los otros dos tipos. Ice Breakers y menú son
 * listas únicas: hay UNA lista de cada una. Los menús de opciones son varios y
 * conviven — uno para quien comenta un post de jabones y otro para quien
 * comenta uno de velas — así que cada opción necesita saber a qué menú
 * pertenece. Los otros dos kinds lo dejan en null.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El enum de `kind` se amplía con un ALTER crudo: doctrine/dbal no
        // sabe modificar enums de MySQL y change() sobre una columna enum
        // borraría los valores existentes.
        //
        // Solo en MySQL: SQLite —el motor de los tests— no tiene tipo ENUM. Lo
        // declara como TEXT y acepta cualquier valor, así que no hay nada que
        // ampliar y el ALTER daría un error de sintaxis.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE instagram_automations
                 MODIFY COLUMN kind ENUM('ice_breaker', 'menu_item', 'quick_reply') NOT NULL"
            );
        }

        Schema::table('instagram_automations', function (Blueprint $table): void {
            // A qué menú pertenece esta opción. Null en ice_breaker y menu_item,
            // que son listas únicas y no se agrupan.
            //
            // nullOnDelete: borrar un menú deja sus opciones huérfanas en vez de
            // borrarlas en cascada. Es deliberado — esas opciones pueden estar
            // ya publicadas en Meta y desaparecer de la base sin más dejaría
            // postbacks que el CRM no sabe resolver, que es justo el caso que
            // handlePostback() registra como warning.
            $table->foreignId('menu_group_id')
                ->nullable()
                ->after('kind')
                ->constrained('instagram_quick_reply_menus')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('instagram_automations', function (Blueprint $table): void {
            $table->dropForeign(['menu_group_id']);
            $table->dropColumn('menu_group_id');
        });

        // Las opciones de menú se borran antes de estrechar el enum: si
        // quedara alguna, MySQL la convertiría en cadena vacía en silencio.
        DB::table('instagram_automations')->where('kind', 'quick_reply')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE instagram_automations
                 MODIFY COLUMN kind ENUM('ice_breaker', 'menu_item') NOT NULL"
            );
        }
    }
};
