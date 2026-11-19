<?php

namespace App\Http\Middleware;

use App\Application\Security\RecaptchaVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Exige el campo `captcha` (la respuesta del widget de reCAPTCHA) en las rutas públicas que se pueden abusar. */
final class RequireCaptcha
{
    public function __construct(private readonly RecaptchaVerifier $captcha) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->captcha->enabled() && ! $this->captcha->verify($request->input('captcha'), $request->ip())) {
            throw ValidationException::withMessages(['captcha' => ['Completá el captcha para continuar.']]);
        }

        return $next($request);
    }
}
