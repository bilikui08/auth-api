<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // En la base el 2FA nace activo; los tests de cada módulo no lo necesitan (los de seguridad lo piden con withTwoFactor())
            'two_factor_enabled' => false,
        ];
    }

    /** Con el 2FA configurado y confirmado (secreto fijo para poder calcular los códigos en los tests). */
    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** Con el 2FA activo pero sin configurar (es lo que tiene toda persona al principio). */
    public function withTwoFactorPending(): static
    {
        return $this->state(fn (array $attributes) => ['two_factor_enabled' => true]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
