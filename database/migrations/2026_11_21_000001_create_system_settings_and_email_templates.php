<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ajustes de toda la plataforma (no de una empresa). Sin valor rige el del .env. Los secretos (SMTP, claves) NO van acá.
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Plantillas de correo personalizadas. Una fila existe solo si alguien editó la plantilla: sin fila rige el original del código.
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('subject', 150);
            $table->string('heading', 120);
            $table->text('body');
            $table->string('button_label', 40);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('system_settings');
    }
};
