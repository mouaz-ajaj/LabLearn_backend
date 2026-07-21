<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $password = config('lablearn.demo_password');

        if (! app()->isLocal() || ! is_string($password) || $password === '') {
            $this->command?->warn('Demo users skipped. Set LABLEARN_DEMO_PASSWORD in a local environment to create them.');

            return;
        }

        $this->seedDemoUser(
            name: 'Demo User',
            email: 'user@lablearn.demo',
            role: UserRole::Regular,
            password: $password,
        );

        $this->seedDemoUser(
            name: 'Demo Student',
            email: 'student@lablearn.demo',
            role: UserRole::Student,
            password: $password,
            studyYear: '4',
        );
    }

    private function seedDemoUser(
        string $name,
        string $email,
        UserRole $role,
        string $password,
        ?string $studyYear = null,
    ): void {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'role' => $role,
            'study_year' => $studyYear,
            'deleted_at' => null,
        ])->save();
    }
}
