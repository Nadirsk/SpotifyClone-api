<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/auth/login';

    private const INVALID_CREDENTIALS = 'The provided credentials are incorrect.';

    public function test_login_with_correct_credentials_returns_200_with_token(): void
    {
        // UserFactory hashes the literal string 'password'.
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson(self::ENDPOINT, [
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
            ])
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertIsString($response->json('data.token'));
        $this->assertNotSame('', $response->json('data.token'));
    }

    public function test_login_fails_validation_when_fields_are_missing(): void
    {
        $response = $this->postJson(self::ENDPOINT, []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson(self::ENDPOINT, [
            'email' => 'ada@example.com',
            'password' => 'totally-wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonPath('errors.email.0', self::INVALID_CREDENTIALS);
    }

    /**
     * The whole point of the generic message: a wrong password for a real
     * account and a login attempt against an email that was never registered
     * must be indistinguishable to the caller, or the endpoint becomes an
     * account-enumeration oracle. Asserted here as byte-identical response
     * bodies, not just "same message string".
     */
    public function test_login_fails_identically_for_wrong_password_and_unknown_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $wrongPassword = $this->postJson(self::ENDPOINT, [
            'email' => 'ada@example.com',
            'password' => 'totally-wrong-password',
        ]);

        $unknownEmail = $this->postJson(self::ENDPOINT, [
            'email' => 'nobody-registered@example.com',
            'password' => 'whatever-guess',
        ]);

        $wrongPassword->assertStatus(422);
        $unknownEmail->assertStatus(422);

        $this->assertSame(
            $wrongPassword->getContent(),
            $unknownEmail->getContent(),
            'Wrong password and unknown email must produce byte-identical response bodies.',
        );
    }

    /**
     * A null `password` column marks an OAuth-only account. No password
     * should ever authenticate it, and the failure must read exactly like any
     * other failed login — a distinct message would reveal that the address
     * belongs to an OAuth-only account.
     */
    public function test_login_fails_for_a_user_with_no_password_using_the_generic_message(): void
    {
        User::factory()->withoutPassword()->create(['email' => 'oauth-only@example.com']);

        $response = $this->postJson(self::ENDPOINT, [
            'email' => 'oauth-only@example.com',
            'password' => 'any-password-at-all',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonPath('errors.email.0', self::INVALID_CREDENTIALS);
    }
}
