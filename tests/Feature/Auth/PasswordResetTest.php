<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function resetPasswordPayload(string $token, array $overrides = []): array
{
    return array_merge([
        'email' => 'moaz@example.com',
        'token' => $token,
        'password' => 'NewStrongPassword123!',
        'password_confirmation' => 'NewStrongPassword123!',
    ], $overrides);
}

test('forgot password returns a neutral response for an existing account', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'moaz@example.com']);

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => ' MOAZ@EXAMPLE.COM ',
    ])->assertSuccessful()
        ->assertExactJson([
            'success' => true,
            'message' => 'If an account exists for this email, password reset instructions have been sent.',
        ]);

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('forgot password returns the same neutral response for an unknown account', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'unknown@example.com',
    ])->assertSuccessful()
        ->assertExactJson([
            'success' => true,
            'message' => 'If an account exists for this email, password reset instructions have been sent.',
        ]);

    Notification::assertNothingSent();
});

test('a valid password reset changes the password and revokes all tokens', function () {
    $user = User::factory()->create([
        'email' => 'moaz@example.com',
        'password' => 'OldStrongPassword123!',
    ]);
    $oldToken = $user->createToken('existing-device')->plainTextToken;
    $resetToken = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', resetPasswordPayload($resetToken))
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    $user->refresh();

    expect(Hash::check('NewStrongPassword123!', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    $this->withToken($oldToken)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

test('an invalid reset token fails safely without changing the password', function () {
    $user = User::factory()->create([
        'email' => 'moaz@example.com',
        'password' => 'OldStrongPassword123!',
    ]);

    $this->postJson('/api/v1/auth/reset-password', resetPasswordPayload('invalid-token'))
        ->assertUnprocessable()
        ->assertExactJson([
            'success' => false,
            'message' => 'Password reset failed.',
            'error_code' => 'PASSWORD_RESET_FAILED',
        ]);

    expect(Hash::check('OldStrongPassword123!', $user->refresh()->password))->toBeTrue();
});
