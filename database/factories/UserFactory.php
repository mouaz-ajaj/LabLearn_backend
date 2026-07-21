<?php

namespace Database\Factories;

use App\Enums\UserRole;
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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Regular,
            'study_year' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function regular(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Regular,
            'study_year' => null,
        ]);
    }

    public function student(string $studyYear = '4'): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Student,
            'study_year' => $studyYear,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
