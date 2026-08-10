<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos estructurados del curso por ciudad.
 *
 * Hasta ahora el precio vivía DENTRO del texto de la plantilla
 * ("• Curso de Velas: $130"), lo cual sirve para copiar y pegar en un chat
 * pero no para que el endpoint de Flows arme la pantalla de información.
 *
 * Sacarlos a columnas permite que exista una sola fuente de verdad: si suben
 * el precio de Caracas, se cambia en un lugar y tanto el Flow como la vista
 * de plantillas lo reflejan.
 *
 * Los tres campos son nullable porque las plantillas genéricas (métodos de
 * pago, confirmación de reserva) no tienen precio ni frecuencia asociados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            // Precio del curso en USD. decimal y no integer porque el negocio
            // podría cotizar con centavos, y migrar después obliga a tocar
            // datos existentes.
            $table->decimal('price', 8, 2)->nullable()->after('category');

            // Monto de la reserva que adelanta el cliente. Hoy son $20 en todas
            // las ciudades, pero se guarda por plantilla por si cambia en alguna.
            $table->decimal('deposit', 8, 2)->nullable()->after('price');

            // Qué incluye el curso: desayuno, refrigerio, café, materiales.
            $table->text('includes')->nullable()->after('deposit');

            // Cada cuánto visitan esa ciudad ("Cada mes", "Cada 2 meses").
            // Texto libre: el negocio lo expresa en lenguaje natural y no hay
            // cálculo que dependa de ello.
            $table->string('visit_frequency', 80)->nullable()->after('includes');

            // Horario de la jornada ("10:00 am a 6:00 pm").
            $table->string('schedule', 80)->nullable()->after('visit_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            $table->dropColumn(['price', 'deposit', 'includes', 'visit_frequency', 'schedule']);
        });
    }
};
