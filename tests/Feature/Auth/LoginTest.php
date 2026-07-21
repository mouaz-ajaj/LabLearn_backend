<?php

use App\Models\User;

function loginPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'moaz@example.com',
        'password' => 'StrongPassword123!',
    ], $overrides);
}

test('a regular user can log in', function () {
    User::factory()->regular()->create([
        'email' => 'moaz@example.com',
        'password' => 'StrongPassword123!',
    ]);

    $this->postJson('/api/v1/auth/login', loginPayload([
        'email' => ' MOAZ@EXAMPLE.COM ',
    ]))->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.role', 'regular')
        ->assertJsonStructure(['data' => ['token']]);
});

test('a student can log in', function () {
    User::factory()->student('3')->create([
        'email' => 'moaz@example.com',
        'password' => 'StrongPassword123!',
    ]);

    $this->postJson('/api/v1/auth/login', loginPayload())
        ->assertSuccessful()
        ->assertJsonPath('data.user.role', 'student')
        ->assertJsonPath('data.user.study_year', '3');
});

test('an invalid password returns a generic credentials error', function () {
    User::factory()->create([
        'email' => 'moaz@example.com',
        'password' => 'StrongPassword123!',
    ]);

    $this->postJson('/api/v1/auth/login', loginPayload([
        'password' => 'WrongPassword123!',
    ]))->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid credentials.',
            'error_code' => 'INVALID_CREDENTIALS',
        ]);
});

test('an unknown email returns the same generic credentials error', function () {
    $this->postJson('/api/v1/auth/login', loginPayload())
        ->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'message' => 'Invalid credentials.',
            'error_code' => 'INVALID_CREDENTIALS',
        ]);
});

test('login returns a sanctum token and preserves existing sessions', function () {
    $user = User::factory()->create([
        'email' => 'moaz@example.com',
        'password' => 'StrongPassword123!',
    ]);
    $user->createToken('existing-device');

    $response = $this->postJson('/api/v1/auth/login', loginPayload());

    $response->assertSuccessful()->assertJsonStructure(['data' => ['token']]);
    expect($user->tokens()->count())->toBe(2);
});

test('login is rate limited by account and ip', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', loginPayload())
            ->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', loginPayload())
        ->assertTooManyRequests()
        ->assertJsonPath('error_code', 'RATE_LIMITED');
});
