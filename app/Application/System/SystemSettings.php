<?php

namespace App\Application\System;

use App\Models\SystemSetting;

/**
 * Ajustes de la plataforma que edita la administración del sistema desde la aplicación. Hoy: el nombre del sistema.
 * Sin valor guardado rige el del `.env` (APP_NAME). Los secretos (SMTP, claves) NO se guardan acá: viven en el `.env`.
 */
final class SystemSettings
{
    public const NAME = 'system_name';
    public const NAME_MAX = 60;

    public function defaultName(): string
    {
        return (string) config('app.default_name');
    }

    public function systemName(): string
    {
        $stored = SystemSetting::query()->where('key', self::NAME)->value('value');

        return is_string($stored) && trim($stored) !== '' ? trim($stored) : $this->defaultName();
    }

    /** null o vacío = volver al nombre del .env. */
    public function setSystemName(?string $name, int $userId): void
    {
        $name = $name === null ? '' : trim($name);

        if ($name === '') {
            SystemSetting::query()->where('key', self::NAME)->delete();

            return;
        }

        SystemSetting::query()->updateOrCreate(['key' => self::NAME], ['value' => $name, 'updated_by' => $userId]);
    }

    /**
     * Antes de armar un correo: el encabezado y el pie de la plantilla de Laravel leen `app.name` y el logo enlaza a `app.url`.
     * El remitente lo fija cada correo (ver TemplatedMail).
     */
    public function applyToMail(): void
    {
        config([
            'app.name' => $this->systemName(),
            'app.url' => rtrim((string) config('app.frontend_url'), '/') ?: config('app.url'),
        ]);
    }
}
