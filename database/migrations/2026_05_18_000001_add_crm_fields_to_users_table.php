<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extiende la tabla users que trae Laravel por defecto.
// Le agrega los campos necesarios para el sistema de roles y control de acceso del CRM.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Rol del usuario dentro del CRM. Superadmin tiene acceso total; administrador es un agente normal.
            $table->enum('role', ['superadmin', 'administrador'])->default('administrador')->after('email');

            // URL de la foto de perfil del agente (opcional, para mostrar en la UI).
            $table->string('avatar_url')->nullable()->after('role');

            // Permite desactivar un agente sin borrarlo. Los desactivados no pueden iniciar sesión.
            $table->boolean('is_active')->default(true)->after('avatar_url');

            // Fecha y hora del último login exitoso. Útil para auditoría del Superadmin.
            $table->timestamp('last_login_at')->nullable()->after('is_active');

            // Soft delete: en lugar de borrar el registro, marca deleted_at.
            // Protege el historial de actividad asociado al usuario.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'avatar_url', 'is_active', 'last_login_at', 'deleted_at']);
        });
    }
};
