<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/auth/register';

    public function test_register_returns_201_with_user_and_token(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Registration successful',
            ])
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.name', 'Ada Lovelace')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'avatar', 'country', 'language', 'created_at'],
                    'token',
                    'token_type',
                ],
            ])
            // The password hash and other internal columns must never reach the client.
            ->assertJsonMissingPath('data.user.password');

        $this->assertIsString($response->json('data.token'));
        $this->assertNotSame('', $response->json('data.token'));

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('super-secret-1', $user->password));
    }

    public function test_register_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson(self::ENDPOINT, [
            'name' => 'Someone Else',
            'email' => 'taken@example.com',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonPath('errors.email.0', 'An account with this email already exists.');

        $this->assertSame(1, User::query()->where('email', 'taken@example.com')->count());
    }

    public function test_register_fails_with_password_too_short(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'name' => 'Short Pass',
            'email' => 'shortpass@example.com',
            // 6 characters — below the explicit `min:8` floor.
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'shortpass@example.com']);
    }

    public function test_register_fails_with_password_confirmation_mismatch(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'password' => 'super-secret-1',
            'password_confirmation' => 'a-totally-different-secret',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);
    }
}
