<?php

namespace App\Application\Security;

use App\Application\System\SystemSettings;
use App\Domain\Auth\Exceptions\InvalidCredentials;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Verificación en dos pasos con una aplicación de autenticación (TOTP). Está activa por defecto para todas las personas
 * (`users.two_factor_enabled`): quien todavía no la configuró la configura en el próximo ingreso. Se puede apagar desde la cuenta
 * (pide la contraseña y un código); el secreto y los códigos de recuperación se guardan cifrados.
 */
final class TwoFactorService
{
    private const RECOVERY_CODES = 10;
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // sin 0/O ni 1/I/L: se copian a mano
    /** Códigos erróneos que tolera una persona (de todos sus ingresos juntos) antes de bloquear la verificación. */
    private const MAX_FAILURES = 10;
    private const LOCK_SECONDS = 900;

    public function __construct(private readonly SystemSettings $system) {}

    /** ¿Hay que pedir el segundo factor al ingresar? */
    public function required(User $user): bool
    {
        return (bool) $user->two_factor_enabled;
    }

    public function isConfirmed(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /** @return array{enabled: bool, confirmed: bool, recovery_codes_remaining: int} */
    public function status(User $user): array
    {
        return [
            'enabled' => $this->required($user) && $this->isConfirmed($user),
            'confirmed' => $this->isConfirmed($user),
            'recovery_codes_remaining' => count((array) $user->two_factor_recovery_codes),
        ];
    }

    /**
     * Prepara (o retoma) la configuración: el secreto queda guardado sin confirmar hasta que se pruebe un código.
     *
     * @return array{secret: string, uri: string}
     */
    public function beginSetup(User $user): array
    {
        if ($this->isConfirmed($user) && $user->two_factor_enabled) {
            throw ValidationException::withMessages(['code' => ['La verificación en dos pasos ya está activa.']]);
        }

        // Con la fila bloqueada: si la pantalla pide el secreto dos veces casi a la vez (recarga, doble clic), el segundo pedido ve el
        // secreto que generó el primero. Sin esto cada pedido generaba uno distinto y la pantalla podía mostrar uno que la base no guarda.
        DB::transaction(function () use ($user): void {
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($fresh->two_factor_secret === null || $fresh->two_factor_confirmed_at !== null) {
                $fresh->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
            }

            $user->setRawAttributes($fresh->getAttributes(), true);
        });

        return ['secret' => $user->two_factor_secret, 'uri' => Totp::uri($user->two_factor_secret, $user->email, $this->system->systemName())];
    }

    /**
     * Confirma la configuración con un código de la aplicación, activa el 2FA y entrega los códigos de recuperación (la única vez
     * que se ven).
     *
     * @return list<string>
     * @throws ValidationException
     */
    public function confirmSetup(User $user, string $code): array
    {
        $this->guardAgainstBruteForce($user);

        $step = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null
            ? Totp::verify($user->two_factor_secret, $code)
            : null;

        if ($step === null) {
            $this->fail($user);
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => $this->hashed($codes),
        ])->save();
        RateLimiter::clear($this->limiterKey($user));

        return $codes;
    }

    /**
     * Valida el segundo factor de un ingreso o de una acción sensible: un código de la aplicación o uno de recuperación (de un solo uso).
     *
     * @throws ValidationException
     */
    public function verify(User $user, string $input): void
    {
        $this->guardAgainstBruteForce($user);

        if ($this->check($user, $input)) {
            RateLimiter::clear($this->limiterKey($user));

            return;
        }

        $this->fail($user);
    }

    /** Apaga el 2FA (exige la contraseña y un código vigente) y borra el secreto y los códigos. */
    public function disable(User $user, string $password, string $code): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['La contraseña no es correcta.']]);
        }

        if ($this->isConfirmed($user)) {
            $this->verify($user, $code);
        }

        $this->reset($user, enabled: false);
    }

    /**
     * Códigos de recuperación nuevos (anulan los anteriores). Exige un código vigente de la aplicación.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        $this->verify($user, $code);

        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $this->hashed($codes)])->save();

        return $codes;
    }

    /** Borra la configuración. `enabled: true` obliga a configurarla de nuevo en el próximo ingreso (por ejemplo, si perdió el celular). */
    public function reset(User $user, bool $enabled): void
    {
        $user->forceFill([
            'two_factor_enabled' => $enabled,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    private function check(User $user, string $input): bool
    {
        if (! $this->isConfirmed($user)) {
            return false;
        }

        $normalized = strtoupper(preg_replace('/[\s-]+/', '', $input) ?? '');

        if (preg_match('/^\d{6}$/', $normalized)) {
            return DB::transaction(function () use ($user, $normalized): bool {
                $fresh = User::query()->lockForUpdate()->find($user->id);
                $step = Totp::verify($fresh->two_factor_secret, $normalized, $fresh->two_factor_last_step);

                if ($step === null) {
                    return false;
                }

                $fresh->forceFill(['two_factor_last_step' => $step])->save();
                $user->two_factor_last_step = $step;

                return true;
            });
        }

        return $this->useRecoveryCode($user, $normalized);
    }

    private function useRecoveryCode(User $user, string $normalized): bool
    {
        if (strlen($normalized) !== 10) {
            return false;
        }

        return DB::transaction(function () use ($user, $normalized): bool {
            $fresh = User::query()->lockForUpdate()->find($user->id);
            $hash = $this->hash($normalized);
            $stored = (array) $fresh->two_factor_recovery_codes;
            $index = null;

            foreach ($stored as $i => $candidate) {
                if (hash_equals((string) $candidate, $hash)) {
                    $index = $i;
                }
            }

            if ($index === null) {
                return false;
            }

            unset($stored[$index]);
            $fresh->forceFill(['two_factor_recovery_codes' => array_values($stored)])->save();
            $user->two_factor_recovery_codes = array_values($stored);

            return true;
        });
    }

    /** @return list<string> Formato XXXXX-XXXXX */
    private function newRecoveryCodes(): array
    {
        return array_map(function (): string {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }

            return substr($raw, 0, 5) . '-' . substr($raw, 5);
        }, range(1, self::RECOVERY_CODES));
    }

    /** @param list<string> $codes @return list<string> */
    private function hashed(array $codes): array
    {
        return array_map(fn (string $code): string => $this->hash(str_replace('-', '', $code)), $codes);
    }

    /** Los códigos son largos y al azar: alcanza un hash con la clave de la aplicación (bcrypt por cada intento no aporta nada). */
    private function hash(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    private function limiterKey(User $user): string
    {
        return 'two-factor:' . $user->id;
    }

    private function guardAgainstBruteForce(User $user): void
    {
        if (RateLimiter::tooManyAttempts($this->limiterKey($user), self::MAX_FAILURES)) {
            throw new ThrottleRequestsException('Demasiados intentos con códigos incorrectos. Esperá unos minutos y probá de nuevo.');
        }
    }

    /** @throws ValidationException */
    private function fail(User $user): never
    {
        RateLimiter::hit($this->limiterKey($user), self::LOCK_SECONDS);

        throw ValidationException::withMessages(['code' => ['El código no es correcto o venció.']]);
    }
}
