<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const FORGOT_ENDPOINT = '/api/v1/auth/forgot-password';

    private const RESET_ENDPOINT = '/api/v1/auth/reset-password';

    private const LOGIN_ENDPOINT = '/api/v1/auth/login';

    private const FORGOT_SUCCESS_MESSAGE = 'If an account exists for that email, a password reset link has been sent.';

    /**
     * The documented security requirement: forgot-password must respond
     * identically whether or not the address has an account, otherwise the
     * endpoint becomes a signup-form-shaped account-enumeration oracle.
     */
    public function test_forgot_password_returns_the_identical_response_for_an_existing_and_an_unknown_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $existing = $this->postJson(self::FORGOT_ENDPOINT, ['email' => 'ada@example.com']);
        $unknown = $this->postJson(self::FORGOT_ENDPOINT, ['email' => 'nobody-registered@example.com']);

        $existing->assertStatus(200)->assertJson([
            'success' => true,
            'message' => self::FORGOT_SUCCESS_MESSAGE,
            'data' => null,
        ]);
        $unknown->assertStatus(200)->assertJson([
            'success' => true,
            'message' => self::FORGOT_SUCCESS_MESSAGE,
            'data' => null,
        ]);

        $this->assertSame(
            $existing->getContent(),
            $unknown->getContent(),
            'forgot-password must respond identically whether or not the email is registered.',
        );
    }

    public function test_forgot_password_fails_validation_without_an_email(): void
    {
        $response = $this->postJson(self::FORGOT_ENDPOINT, []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonValidationErrors('email');
    }

    public function test_reset_password_with_a_valid_token_allows_login_with_the_new_password_and_revokes_prior_tokens(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        // A session that must die the moment the password is reset.
        $priorToken = $user->createToken('api');
        $priorTokenId = $priorToken->accessToken->getKey();

        $resetToken = Password::createToken($user);

        $response = $this->postJson(self::RESET_ENDPOINT, [
            'email' => 'ada@example.com',
            'token' => $resetToken,
            'password' => 'brand-new-secret-1',
            'password_confirmation' => 'brand-new-secret-1',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password reset successfully. Please sign in again.',
                'data' => null,
            ]);

        $this->assertNull(PersonalAccessToken::find($priorTokenId));

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-secret-1', $user->password));

        $this->postJson(self::LOGIN_ENDPOINT, [
            'email' => 'ada@example.com',
            'password' => 'brand-new-secret-1',
        ])->assertStatus(200)->assertJson(['success' => true]);

        // The factory's original password must no longer work.
        $this->postJson(self::LOGIN_ENDPOINT, [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_reset_password_fails_with_an_invalid_token(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson(self::RESET_ENDPOINT, [
            'email' => 'ada@example.com',
            'token' => 'not-a-real-token',
            'password' => 'brand-new-secret-1',
            'password_confirmation' => 'brand-new-secret-1',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonPath('errors.email.0', 'This password reset token is invalid or has expired.');
    }

    /**
     * Same fixed message as an invalid token — an unknown email must not
     * resolve to a different error than a wrong token for a real one.
     */
    public function test_reset_password_fails_identically_for_an_unknown_email(): void
    {
        $unknownEmail = $this->postJson(self::RESET_ENDPOINT, [
            'email' => 'nobody-registered@example.com',
            'token' => 'not-a-real-token',
            'password' => 'brand-new-secret-1',
            'password_confirmation' => 'brand-new-secret-1',
        ]);

        $unknownEmail->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This password reset token is invalid or has expired.');
    }

    public function test_reset_password_fails_validation_with_a_short_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $resetToken = Password::createToken($user);

        $response = $this->postJson(self::RESET_ENDPOINT, [
            'email' => 'ada@example.com',
            'token' => $resetToken,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonValidationErrors('password');
    }
}
