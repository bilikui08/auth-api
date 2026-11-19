<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\DTO\RegisterData;
use App\Domain\Auth\DTO\ResetPasswordData;
use App\Domain\Auth\Exceptions\PasswordResetNotValid;
use App\Mail\PasswordResetMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Throwable;

final class EloquentAuthRepository implements AuthRepository
{
    public function create(RegisterData $data): User
    {
        $user = User::query()->create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password,
        ]);

        // Todo usuario nuevo nace con el rol "user"; los demas roles los asigna un admin.
        $defaultRole = Role::query()->where('name', Role::USER)->value('id');
        if ($defaultRole !== null) {
            $user->roles()->attach($defaultRole);
        }

        return $user;
    }

    public function findByIdentifier(string $identifier): ?User
    {
        return User::query()
            ->where('email', $identifier)
            ->orWhere('name', $identifier)
            ->first();
    }

    public function sendPasswordResetLink(string $email): void
    {
        // La respuesta al usuario es siempre la misma (no revela qué correos están registrados). El mail sale en el momento
        // (no hay procesos en segundo plano en el hosting); si falla se reporta, pero no se le cuenta a quien lo pidió.
        Password::broker()->sendResetLink(['email' => $email], function (User $user, string $token): void {
            $url = sprintf('%s/reset-password?token=%s&email=%s', rtrim((string) config('app.frontend_url'), '/'), urlencode($token), urlencode($user->email));

            try {
                Mail::to($user->email)->send(new PasswordResetMail($url, (int) config('auth.passwords.users.expire')));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    public function passwordResetIsValid(string $email, string $token): bool
    {
        $broker = Password::broker();
        $user = $broker->getUser(['email' => $email]);

        return $user !== null && $broker->tokenExists($user, $token);
    }

    public function resetPassword(ResetPasswordData $data): void
    {
        $status = Password::broker()->reset(
            ['email' => $data->email, 'token' => $data->token, 'password' => $data->password],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                    // Quien cambia la contraseña espera que las sesiones abiertas (por ejemplo, la de quien la había averiguado) dejen de servir
                    $tokenIds = Passport::token()->newQuery()->where('user_id', $user->id)->pluck('id');
                    Passport::token()->newQuery()->whereIn('id', $tokenIds)->update(['revoked' => true]);
                    Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new PasswordResetNotValid;
        }
    }
}
