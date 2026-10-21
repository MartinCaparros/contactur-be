<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->name(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'pending_email' => null,
            'pending_email_requested_at' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password-para-tests'),
            'system_role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'locale' => 'es-AR',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'notification_preferences' => [],
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS_SUSPENDED,
        ]);
    }
}
