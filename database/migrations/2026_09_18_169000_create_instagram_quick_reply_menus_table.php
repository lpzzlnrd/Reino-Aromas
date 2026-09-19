<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los menús de opciones de Instagram: el mensaje y sus botones.
 *
 * Cada fila es UN menú — el texto que lo acompaña más la lista de opciones,
 * que viven en `instagram_automations` con kind='quick_reply'.
 *
 * ## Por qué una tabla propia y no reutilizar instagram_comment_settings
 *
 * Esa es una tabla de UNA fila: la configuración global del DM por comentario.
 * Los menús son varios y el negocio necesita elegir cuál se manda — uno para
 * quien pregunta por cursos y otro para quien pregunta por productos. Meterlos
 * en la tabla de una fila obligaría a convertirla en multi-fila y rehacer
 * actual(), que es el acceso que usa todo el servicio de comentarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_quick_reply_menus', function (Blueprint $table): void {
            $table->id();

            // Cómo lo reconoce el admin en el CRM. No lo ve el cliente.
            $table->string('name', 120);

            // El texto que acompaña a las burbujas. Instagram lo muestra como
            // un mensaje normal y las opciones aparecen sobre el teclado.
            //
            // 1000 y no más: es el tope de un DM de Instagram, el mismo que
            // ya valida InstagramService::MAX_CARACTERES_DM.
            $table->string('body', 1000);

            // Un menú inactivo no se ofrece en los selectores ni se envía. Es
            // la forma de retirar uno de temporada sin perder sus opciones ni
            // las estadísticas que acumuló.
            $table->boolean('is_active')->default(true);

            // Cuántas veces se envió. Sirve para que el admin vea cuál de sus
            // menús trabaja de verdad antes de retirarlo.
            $table->unsignedInteger('sends')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_quick_reply_menus');
    }
};
