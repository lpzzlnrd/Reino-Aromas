<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que el DM automático por comentario sea un menú de opciones.
 *
 * Hasta ahora ese DM solo podía ser texto o plantilla: un mensaje suelto al
 * que la persona contesta escribiendo. Con un menú, contesta tocando —y cada
 * opción dispara su propia respuesta sin que intervenga un agente.
 *
 * ## Por qué importa el orden (el menú va en el PRIMER mensaje)
 *
 * Meta permite UN SOLO DM por comentario: hasta que la persona responda, no se
 * puede mandar un segundo. Así que el menú tiene que viajar en ese primer
 * mensaje. Mandar un saludo y después el menú no funciona — el segundo envío
 * se rechaza y la persona queda con el saludo y ninguna opción.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Solo en MySQL: SQLite no tiene ENUM (lo guarda como TEXT y acepta
        // cualquier valor), así que el ALTER sobraría y daría error de
        // sintaxis en los tests.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE instagram_comment_settings
                 MODIFY COLUMN response_type ENUM('template', 'text', 'menu') NOT NULL DEFAULT 'text'"
            );
        }

        Schema::table('instagram_comment_settings', function (Blueprint $table): void {
            // El menú a enviar si response_type = 'menu'.
            //
            // nullOnDelete y no cascade: borrar el menú no debe borrar la
            // configuración entera del DM por comentario. Queda apuntando a
            // null y la UI lo muestra como "sin respuesta configurada", igual
            // que ya hace con una plantilla borrada.
            $table->foreignId('quick_reply_menu_id')
                ->nullable()
                ->after('template_id')
                ->constrained('instagram_quick_reply_menus')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('instagram_comment_settings', function (Blueprint $table): void {
            $table->dropForeign(['quick_reply_menu_id']);
            $table->dropColumn('quick_reply_menu_id');
        });

        // Las filas en 'menu' pasan a 'text' antes de estrechar el enum: si
        // quedara alguna, MySQL la convertiría en cadena vacía en silencio y
        // el DM dejaría de salir sin que nada lo explique.
        DB::table('instagram_comment_settings')
            ->where('response_type', 'menu')
            ->update(['response_type' => 'text']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE instagram_comment_settings
                 MODIFY COLUMN response_type ENUM('template', 'text') NOT NULL DEFAULT 'text'"
            );
        }
    }
};
