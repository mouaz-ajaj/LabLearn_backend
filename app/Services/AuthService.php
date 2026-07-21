<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * @param  array{name: string, email: string, password: string, role: string, study_year?: string|null}  $attributes
     * @return array{user: User, token: string}
     */
    public function register(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            $user = new User;
            $user->name = $attributes['name'];
            $user->email = $attributes['email'];
            $user->password = Hash::make($attributes['password']);
            $user->role = UserRole::from($attributes['role']);
            $user->study_year = $user->role === UserRole::Student
                ? $attributes['study_year']
                : null;
            $user->save();

            return [
                'user' => $user,
                'token' => $this->issueToken($user),
            ];
        });
    }

    /**
     * @return array{user: User, token: string}|null
     */
    public function login(string $email, string $password): ?array
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        return [
            'user' => $user,
            'token' => $this->issueToken($user),
        ];
    }

    private function issueToken(User $user): string
    {
        return $user->createToken((string) config('lablearn.token_name'))->plainTextToken;
    }
}
