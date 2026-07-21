<?php

use App\Models\User;

function tokenFor(User $user): string
{
    return $user->createToken('test-device')->plainTextToken;
}

test('a user can read their profile', function () {
    $user = User::factory()->regular()->create();

    $this->withToken(tokenFor($user))
        ->getJson('/api/v1/users/me')
        ->assertSuccessful()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.role', 'regular')
        ->assertJsonMissingPath('data.user.password')
        ->assertJsonMissingPath('data.user.remember_token');
});

test('a user can update their name', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    $this->withToken(tokenFor($user))
        ->patchJson('/api/v1/users/me', ['name' => '  New   Name  '])
        ->assertSuccessful()
        ->assertJsonPath('data.user.name', 'New Name');

    expect($user->refresh()->name)->toBe('New Name');
});

test('a student can update their study year', function () {
    $student = User::factory()->student('2')->create();

    $this->withToken(tokenFor($student))
        ->patchJson('/api/v1/users/me', ['study_year' => '5'])
        ->assertSuccessful()
        ->assertJsonPath('data.user.study_year', '5');

    expect($student->refresh()->study_year)->toBe('5');
});

test('a regular user cannot update a study year', function () {
    $user = User::factory()->regular()->create();

    $this->withToken(tokenFor($user))
        ->patchJson('/api/v1/users/me', ['study_year' => '4'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('study_year');

    expect($user->refresh()->study_year)->toBeNull();
});

test('a user cannot change their role through the profile endpoint', function () {
    $user = User::factory()->regular()->create();

    $this->withToken(tokenFor($user))
        ->patchJson('/api/v1/users/me', ['role' => 'student'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');

    expect($user->refresh()->role->value)->toBe('regular');
});

test('a user cannot update email or password through the profile endpoint', function (string $field, string $value) {
    $user = User::factory()->create();

    $this->withToken(tokenFor($user))
        ->patchJson('/api/v1/users/me', [$field => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'email' => ['email', 'new@example.com'],
    'password' => ['password', 'AnotherPassword123!'],
]);

test('an unauthenticated profile request is rejected', function () {
    $this->getJson('/api/v1/users/me')
        ->assertUnauthorized()
        ->assertJsonPath('error_code', 'UNAUTHENTICATED');
});
