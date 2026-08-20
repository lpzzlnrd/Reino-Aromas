<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cuentas de Meta vinculadas al CRM: una fila por canal conectado.
//
// Antes esto vivía solo en el .env, lo que obligaba al dev a editar el servidor
// para conectar una cuenta y hacía imposible que la UI mostrara el estado real.
// Con esta tabla el flujo de Embedded Signup puede guardar lo que Meta devuelve
// y la vista de Cuentas deja de tener "No vinculado" escrito a mano.
//
// El .env sigue siendo válido como fuente: si un canal no tiene fila aquí pero
// sus META_* están puestas, se considera configurado a mano. La tabla no lo
// reemplaza, lo complementa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_accounts', function (Blueprint $table) {
            $table->id();

            // Canal que representa esta cuenta. Mismo enum que messages y
            // contacts para que las tres tablas hablen el mismo idioma.
            //
            // Unique: un solo negocio, un número de WhatsApp. Si algún día se
            // soportan varias cuentas por canal habrá que quitar esta
            // restricción, pero hoy permitir duplicados solo dejaría al CRM sin
            // saber cuál usar para enviar.
            $table->enum('channel', ['whatsapp', 'instagram', 'facebook'])->unique();

            // Nombre visible de la cuenta ("Reino Aromas", "@reinoaromas").
            // Lo devuelve Meta y es lo único que el agente reconoce: los IDs
            // numéricos no le dicen nada.
            $table->string('display_name')->nullable();

            /*
            |------------------------------------------------------------------
            | Identificadores de Meta
            |
            | Cada canal usa uno distinto y no son intercambiables:
            |   whatsapp  -> phone_number_id (+ waba_id, la cuenta de negocio)
            |   instagram -> instagram_account_id
            |   facebook  -> page_id
            |
            | Se guardan como string y no como entero: son IDs de 15-17 dígitos
            | que Meta documenta como opacos, y algunos superan el rango de INT.
            |------------------------------------------------------------------
            */
            $table->string('external_id', 64)->nullable();

            // Solo WhatsApp: la WhatsApp Business Account que contiene el
            // número. Hace falta para suscribir el webhook y consultar
            // plantillas, que son operaciones sobre la WABA y no sobre el número.
            $table->string('waba_id', 64)->nullable();

            /*
            |------------------------------------------------------------------
            | Token de acceso
            |
            | CIFRADO en reposo con la APP_KEY (cast 'encrypted' en el modelo).
            | Un token de página con permisos de mensajería permite escribir a
            | los clientes en nombre del negocio: en claro, cualquiera con
            | lectura a la base podría suplantar al CRM.
            |
            | Es text y no string porque los tokens de larga duración de Meta
            | rondan los 200 caracteres y el cifrado los expande.
            |------------------------------------------------------------------
            */
            $table->text('access_token')->nullable();

            // Cuándo caduca el token, si Meta lo informa. Los de página son de
            // larga duración (~60 días) y los del sistema no expiran, así que
            // null significa "sin caducidad conocida", no "ya caducó".
            $table->timestamp('token_expires_at')->nullable();

            // Estado de la vinculación.
            //   connected    - operativa
            //   disconnected - el usuario la desvinculó desde el CRM
            //   error        - Meta rechazó el token o revocó los permisos
            //
            // 'error' existe separado de 'disconnected' a propósito: son
            // distintos para el agente. Uno lo hizo él, el otro hay que
            // arreglarlo.
            $table->enum('status', ['connected', 'disconnected', 'error'])->default('connected');

            // Por qué falló, cuando status es 'error'. Se muestra tal cual en la
            // UI: el mensaje de Meta suele decir exactamente qué permiso falta.
            $table->string('error_message')->nullable();

            // Quién la vinculó. Null si vino del .env y no de la UI.
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Última vez que se comprobó contra la Graph API que el token sirve.
            // Sin esto no hay forma de distinguir "funciona" de "nunca se probó".
            $table->timestamp('verified_at')->nullable();

            // Lo que Meta devolvió al vincular, tal cual. Sirve para diagnosticar
            // sin volver a pedirlo y para leer campos que hoy no se usan.
            $table->json('meta_payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_accounts');
    }
};
