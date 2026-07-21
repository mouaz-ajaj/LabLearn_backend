<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

test('logout revokes only the current device token', function () {
    $user = User::factory()->create();
    $currentToken = $user->createToken('current-device')->plainTextToken;
    $otherToken = $user->createToken('other-device')->plainTextToken;

    $this->withToken($currentToken)
        ->postJson('/api/v1/auth/logout')
        ->assertSuccessful()
        ->assertExactJson([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);

    expect(PersonalAccessToken::findToken($currentToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($otherToken))->not->toBeNull();

    $this->withToken($otherToken)
        ->getJson('/api/v1/auth/me')
        ->assertSuccessful();
});

test('a revoked token can no longer authenticate', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-device')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertSuccessful();

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});
