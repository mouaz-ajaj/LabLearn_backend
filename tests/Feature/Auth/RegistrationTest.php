<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Moaz Student',
        'email' => 'moaz@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'role' => UserRole::Regular->value,
    ], $overrides);
}

test('a regular user can register', function () {
    $response = $this->postJson('/api/v1/auth/register', registrationPayload([
        'name' => '  Moaz   User  ',
        'email' => '  MOAZ@EXAMPLE.COM ',
    ]));

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.name', 'Moaz User')
        ->assertJsonPath('data.user.email', 'moaz@example.com')
        ->assertJsonPath('data.user.role', 'regular')
        ->assertJsonPath('data.user.study_year', null)
        ->assertJsonMissingPath('data.user.password');

    $user = User::query()->where('email', 'moaz@example.com')->firstOrFail();

    expect(Hash::check('StrongPassword123!', $user->password))->toBeTrue()
        ->and($user->tokens)->toHaveCount(1);
});

test('a student can register with a study year', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'role' => UserRole::Student->value,
        'study_year' => '4',
    ]))->assertCreated()
        ->assertJsonPath('data.user.role', 'student')
        ->assertJsonPath('data.user.study_year', '4');
});

test('a duplicate normalized email is rejected', function () {
    User::factory()->create(['email' => 'moaz@example.com']);

    $this->postJson('/api/v1/auth/register', registrationPayload([
        'email' => 'MOAZ@EXAMPLE.COM',
    ]))->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors('email');
});

test('an invalid email is rejected', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'email' => 'not-an-email',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('an invalid role is rejected', function (string $role) {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'role' => $role,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('role');
})->with([
    'undefined guest role' => 'guest',
    'undefined owner role' => 'owner',
]);

test('a client cannot register an admin role', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'role' => 'admin',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});

test('a student must provide a study year', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'role' => UserRole::Student->value,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('study_year');
});

test('a regular user does not require a study year', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload())
        ->assertCreated()
        ->assertJsonPath('data.user.study_year', null);
});

test('a regular user cannot submit a nonempty study year', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'study_year' => '4',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('study_year');
});

test('an invalid student study year is rejected', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'role' => UserRole::Student->value,
        'study_year' => '7',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('study_year');
});
