<?php

namespace App\Auth\Passport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Permiso temporal para elegir empresa después de validar las credenciales. No es un access token: solo sirve para
 * elegir empresa en el paso siguiente, vence en minutos y se usa una única vez.
 * Se guarda hasheado: quien lea el caché no puede reutilizarlo.
 */
final class SelectionTokens
{
    public const TTL_SECONDS = 300;

    public function create(int $userId): string
    {
        $token = Str::random(64);

        Cache::put($this->key($token), $userId, self::TTL_SECONDS);

        return $token;
    }

    /** Usuario al que pertenece el permiso, sin consumirlo. */
    public function peek(string $token): ?int
    {
        $userId = Cache::get($this->key($token));

        return $userId === null ? null : (int) $userId;
    }

    public function consume(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'org-selection:' . hash('sha256', $token);
    }
}
