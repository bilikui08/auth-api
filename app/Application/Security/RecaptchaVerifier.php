<?php

namespace App\Application\Security;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifica contra Google la respuesta del reCAPTCHA v2. Sin `RECAPTCHA_SECRET` está apagado (desarrollo y tests).
 * Si Google no responde se rechaza (falla cerrado): un captcha que se salta cuando el servicio falla no protege nada.
 */
final class RecaptchaVerifier
{
    public function enabled(): bool
    {
        return (string) config('services.recaptcha.secret') !== '';
    }

    public function verify(?string $response, ?string $ip): bool
    {
        if ($response === null || $response === '' || strlen($response) > 4096) {
            return false;
        }

        try {
            $result = Http::asForm()->timeout(5)->post((string) config('services.recaptcha.verify_url'), array_filter([
                'secret' => (string) config('services.recaptcha.secret'),
                'response' => $response,
                'remoteip' => $ip,
            ]));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        if (! $result->successful() || $result->json('success') !== true) {
            return false;
        }

        $allowed = (array) config('services.recaptcha.hostnames');

        return $allowed === [] || in_array((string) $result->json('hostname'), $allowed, true);
    }
}
