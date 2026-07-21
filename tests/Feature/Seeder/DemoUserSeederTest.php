<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

test('local demo accounts are seeded from an environment-configured password', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    config()->set('lablearn.demo_password', 'ConfiguredDemoPassword123!');

    $this->seed(DatabaseSeeder::class);

    $regular = User::query()->where('email', 'user@lablearn.demo')->firstOrFail();
    $student = User::query()->where('email', 'student@lablearn.demo')->firstOrFail();

    expect($regular->role)->toBe(UserRole::Regular)
        ->and($regular->study_year)->toBeNull()
        ->and($student->role)->toBe(UserRole::Student)
        ->and($student->study_year)->toBe('4')
        ->and(Hash::check('ConfiguredDemoPassword123!', $student->password))->toBeTrue();
});

test('demo accounts are skipped when no demo password is configured', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    config()->set('lablearn.demo_password');

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->whereIn('email', [
        'user@lablearn.demo',
        'student@lablearn.demo',
    ])->count())->toBe(0);
});
