<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento del Flow de bienvenida por conversación.
 *
 * `flow_sent_at` evita enviar el Flow dos veces al mismo lead: es habitual que
 * un contacto nuevo mande "hola" y "quiero info" con segundos de diferencia, y
 * sin esta marca se encolarían dos envíos.
 *
 * `flow_token` correlaciona la sesión del Flow con la conversación: Meta lo
 * devuelve en cada request al endpoint de datos y en la respuesta final, y es
 * la única forma de saber a qué contacto pertenece lo que se está llenando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestamp('flow_sent_at')->nullable()->after('last_message_at');

            // Indexado porque el endpoint de Flows busca por este campo en cada
            // request para resolver la conversación.
            $table->string('flow_token', 80)->nullable()->unique()->after('flow_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique(['flow_token']);
            $table->dropColumn(['flow_sent_at', 'flow_token']);
        });
    }
};
