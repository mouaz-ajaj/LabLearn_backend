<?php

use App\Models\User;

test('an authenticated user can retrieve the current session', function () {
    $user = User::factory()->student('5')->create();
    $token = $user->createToken('test-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.role', 'student')
        ->assertJsonPath('data.user.study_year', '5')
        ->assertJsonMissingPath('data.user.password');
});

test('an unauthenticated current session request is rejected consistently', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'message' => 'Unauthenticated.',
            'error_code' => 'UNAUTHENTICATED',
        ]);
});
