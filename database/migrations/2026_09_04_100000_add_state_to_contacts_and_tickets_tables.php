<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado del pais (division politico-territorial de Venezuela) para contactos
 * y tickets.
 *
 * Es un corte distinto de `city`, que NO se toca: `city` son las cinco sedes
 * 
 * Se guarda como VARCHAR y no como ENUM a proposito

 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            // Slug del estado (ej: 'distrito_capital', 'zulia'). NULL = sin
            // determinar todavia: los contactos nacen de un webhook de Meta,
            // que no trae ubicacion, asi que el valor lo pone el agente a mano.
            $table->string('state', 40)->nullable()->after('city');

            // El reporte agrupa por estado y el listado de clientes filtra por
            // el: sin indice son dos full scans sobre la tabla que mas crece.
            $table->index('state');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            // Se copia del contacto al crear el ticket, igual que `city`: asi
            // el reporte por estado no necesita un JOIN y un ticket conserva de
            // donde venia el cliente aunque despues se corrija su ficha.
            $table->string('state', 40)->nullable()->after('city');

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['state']);
            $table->dropColumn('state');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['state']);
            $table->dropColumn('state');
        });
    }
};
