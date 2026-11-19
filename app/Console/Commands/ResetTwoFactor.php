<?php

namespace App\Console\Commands;

use App\Application\Security\TwoFactorService;
use App\Models\User;
use Illuminate\Console\Command;

/** Recuperación de una cuenta que perdió el celular y los códigos de recuperación: php artisan auth:2fa-reset tu@mail.com */
final class ResetTwoFactor extends Command
{
    protected $signature = 'auth:2fa-reset {email : Email del usuario} {--disable : Lo deja sin 2FA en lugar de pedirle que lo configure de nuevo}';

    protected $description = 'Borra la verificación en dos pasos de un usuario (vuelve a configurarla en su próximo ingreso, o queda apagada con --disable)';

    public function handle(TwoFactorService $twoFactor): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('Usuario no encontrado.');

            return self::FAILURE;
        }

        $twoFactor->reset($user, enabled: ! $this->option('disable'));
        $this->info($this->option('disable') ? "{$user->email}: 2FA desactivado." : "{$user->email}: deberá configurar el 2FA de nuevo en su próximo ingreso.");

        return self::SUCCESS;
    }
}
