<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respuestas rápidas: las plantillas que se pintan como botones sobre la barra
 * de escritura del chat y se envían con un clic.
 *
 * NO se crea una tabla aparte a propósito. Una respuesta rápida es una
 * plantilla — mismo cuerpo con {{nombre}}, mismo filtrado por ciudad y canal,
 * mismo contador de uso, mismo renderizado en TemplateService. Duplicar todo
 * eso en una tabla `quick_messages` obligaría a mantener dos veces el render de
 * variables y dos gestores en la UI, y el agente tendría que decidir a cuál de
 * los dos sistemas pertenece un texto nuevo.
 *
 * Lo que cambia es solo la PRESENTACIÓN: un subconjunto del catálogo que, por
 * ser el de uso diario, merece estar a un clic en vez de dentro de un
 * desplegable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            // true = se pinta como botón sobre el input del chat.
            //
            // Por defecto false: al activar esta migración, ninguna de las
            // plantillas que ya existen aparece de golpe como botón. La barra
            // se llena a mano desde el gestor, que es lo correcto — quién
            // merece un botón es una decisión del negocio, no del esquema.
            $table->boolean('is_quick_reply')
                ->default(false)
                ->after('is_active');

            // Orden de los botones en la barra. El agente los coloca en el
            // orden del guion de atención (saludar, precios, horarios,
            // despedir), que NO es el orden alfabético ni el de más usadas:
            // el saludo es siempre el primero aunque los precios se manden más.
            $table->unsignedSmallInteger('sort_order')
                ->default(0)
                ->after('is_quick_reply');

            // El chat pide justo "las activas, de acceso rápido, en su orden".
            // Sin este índice es un scan de la tabla en cada apertura de chat.
            $table->index(['is_quick_reply', 'is_active', 'sort_order'], 'templates_quick_reply_index');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            $table->dropIndex('templates_quick_reply_index');
            $table->dropColumn(['is_quick_reply', 'sort_order']);
        });
    }
};
