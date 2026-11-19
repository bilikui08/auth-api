<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación en dos pasos (TOTP: Google Authenticator y similares). Está ACTIVA por defecto para todas las personas: quien todavía no
 * configuró su aplicación la configura en el próximo ingreso. El secreto y los códigos de recuperación se guardan cifrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('two_factor_enabled')->default(true)->after('remember_token');
            $table->text('two_factor_secret')->nullable()->after('two_factor_enabled');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
            // Último intervalo de 30 s ya usado: un mismo código no sirve dos veces
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_enabled', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_last_step']);
        });
    }
};
