<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completa la tabla templates para el gestor de plantillas.
 *
 * La tabla original solo distinguía por ciudad. El equipo atiende por WhatsApp,
 * Instagram y Facebook, y hay textos que solo aplican a un canal (por ejemplo
 * los que mencionan "responde a este mensaje" no tienen sentido en un comentario
 * de IG), así que hace falta filtrar también por canal.
 *
 * Se agrega además el conteo de uso: saber qué plantilla usa el equipo de verdad
 * y cuál nunca se toca es la información que permite depurar la lista con el
 * tiempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            // NULL = sirve para todos los canales. Es el caso más común
            // (precios, métodos de pago), por eso es el valor por defecto.
            $table->enum('channel', ['whatsapp', 'instagram', 'facebook'])
                ->nullable()
                ->after('city');

            // Categoría libre para agrupar en la UI. No es enum a propósito:
            // el negocio va a inventar categorías nuevas y no queremos una
            // migración cada vez.
            $table->string('category', 60)
                ->nullable()
                ->after('channel');

            $table->unsignedInteger('usage_count')
                ->default(0)
                ->after('is_active');

            $table->timestamp('last_used_at')
                ->nullable()
                ->after('usage_count');

            // El listado siempre filtra por activas y ordena por uso.
            $table->index(['is_active', 'city'], 'templates_active_city_index');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            $table->dropIndex('templates_active_city_index');
            $table->dropColumn(['channel', 'category', 'usage_count', 'last_used_at']);
        });
    }
};
