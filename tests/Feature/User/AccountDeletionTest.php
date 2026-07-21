<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

test('an authenticated user can soft delete their account and revoke every token', function () {
    $user = User::factory()->create();
    $currentToken = $user->createToken('current-device')->plainTextToken;
    $otherToken = $user->createToken('other-device')->plainTextToken;

    $this->withToken($currentToken)
        ->deleteJson('/api/v1/users/me')
        ->assertSuccessful()
        ->assertExactJson([
            'success' => true,
            'message' => 'Account deleted successfully.',
        ]);

    $this->assertSoftDeleted($user);
    expect(PersonalAccessToken::findToken($currentToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($otherToken))->toBeNull();
});

test('account deletion requires authentication', function () {
    $this->deleteJson('/api/v1/users/me')
        ->assertUnauthorized()
        ->assertJsonPath('error_code', 'UNAUTHENTICATED');
});
