<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Events\UserRegistered;
use App\Models\User;
use App\Repositories\EloquentUserRepository;
use App\Services\Auth\AuthService;
use App\Services\Auth\DeviceSessionService;
use App\Services\Auth\EmailLoginCodeService;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Exercises AuthService directly against a real database — no mocked
 * Eloquent — for branches that do not need a full HTTP round-trip: the
 * enumeration-resistant login failure paths, the per-email+IP lockout, and
 * token lifecycle side effects.
 */
final class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private const INVALID_CREDENTIALS = 'The provided credentials are incorrect.';

    private const THROTTLED = 'Too many login attempts. Please try again later.';

    private AuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AuthService(
            new EloquentUserRepository,
            app(OtpService::class),
            app(EmailLoginCodeService::class),
            /*
             | Resolved rather than hand-built: it needs the current Request for
             | the device label, and the container already has the one the test
             | harness set up. The cap it enforces never fires in this file —
             | every user here is on the Free tier, which is uncapped.
             */
            app(DeviceSessionService::class),
        );
    }

    public function test_register_persists_a_hashed_password_dispatches_an_event_and_returns_a_token(): void
    {
        Event::fake([UserRegistered::class]);

        $result = $this->service->register([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'super-secret-1',
            'country' => 'IN',
            'language' => 'en',
        ]);

        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertSame('ada@example.com', $result['user']->email);
        $this->assertIsString($result['token']);
        $this->assertNotSame('', $result['token']);

        // The `password` cast on the model hashes on save, not the service.
        $this->assertNotSame('super-secret-1', $result['user']->password);
        $this->assertTrue(Hash::check('super-secret-1', $result['user']->password));

        Event::assertDispatched(
            UserRegistered::class,
            fn (UserRegistered $event): bool => $event->user->is($result['user']),
        );
    }

    public function test_login_returns_a_token_for_correct_credentials(): void
    {
        $user = User::factory()->create(); // UserFactory hashes the literal string 'password'.

        $result = $this->service->login($user->email, 'password', '127.0.0.1');

        $this->assertTrue($result['user']->is($user));
        $this->assertIsString($result['token']);
        $this->assertNotSame('', $result['token']);
    }

    public function test_login_throws_the_generic_message_for_a_wrong_password(): void
    {
        $user = User::factory()->create();

        try {
            $this->service->login($user->email, 'wrong-password', '127.0.0.1');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame([self::INVALID_CREDENTIALS], $e->errors()['email']);
        }
    }

    public function test_login_throws_the_same_generic_message_for_an_unknown_email(): void
    {
        try {
            $this->service->login('nobody-registered@example.com', 'whatever', '127.0.0.1');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame([self::INVALID_CREDENTIALS], $e->errors()['email']);
        }
    }

    public function test_login_throws_the_same_generic_message_for_an_oauth_only_account(): void
    {
        $user = User::factory()->withoutPassword()->create();

        try {
            $this->service->login($user->email, 'any-password-at-all', '127.0.0.1');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame([self::INVALID_CREDENTIALS], $e->errors()['email']);
        }
    }

    /**
     * Pins the tighter per-email+IP lockout (5 attempts / 60s) that sits
     * underneath the global per-minute throttle — it only ever engages after
     * repeated failures, so it is easy for a refactor to silently break.
     */
    public function test_login_locks_out_after_five_failed_attempts_from_the_same_ip(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->service->login($user->email, 'wrong-password', '127.0.0.1');
                $this->fail('Expected a ValidationException on attempt '.($i + 1));
            } catch (ValidationException $e) {
                $this->assertSame([self::INVALID_CREDENTIALS], $e->errors()['email']);
            }
        }

        // The 6th attempt is locked out before credentials are even checked.
        try {
            $this->service->login($user->email, 'wrong-password', '127.0.0.1');
            $this->fail('Expected a throttled ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame([self::THROTTLED], $e->errors()['email']);
        }
    }

    public function test_login_lockout_is_scoped_per_ip_so_a_different_ip_is_unaffected(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->service->login($user->email, 'wrong-password', '10.0.0.1');
                $this->fail('Expected a ValidationException on attempt '.($i + 1));
            } catch (ValidationException) {
                // Exhausting the lockout for 10.0.0.1 only.
            }
        }

        // A second IP guessing the same account's password is a fresh bucket.
        $result = $this->service->login($user->email, 'password', '10.0.0.2');

        $this->assertTrue($result['user']->is($user));
    }

    public function test_logout_deletes_only_the_current_access_token(): void
    {
        $user = User::factory()->create();

        $tokenA = $user->createToken('api');
        $tokenB = $user->createToken('api');

        $user->withAccessToken($tokenA->accessToken);

        $this->service->logout($user);

        $this->assertNull(PersonalAccessToken::find($tokenA->accessToken->getKey()));
        $this->assertNotNull(PersonalAccessToken::find($tokenB->accessToken->getKey()));
    }

    public function test_reset_password_updates_the_password_and_revokes_all_tokens(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = $user->createToken('api');
        $tokenId = $token->accessToken->getKey();

        $resetToken = Password::createToken($user);

        $this->service->resetPassword([
            'email' => 'ada@example.com',
            'token' => $resetToken,
            'password' => 'brand-new-secret-1',
            'password_confirmation' => 'brand-new-secret-1',
        ]);

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-secret-1', $user->password));
        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    public function test_reset_password_throws_the_generic_message_for_an_invalid_token(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        try {
            $this->service->resetPassword([
                'email' => 'ada@example.com',
                'token' => 'not-a-real-token',
                'password' => 'brand-new-secret-1',
                'password_confirmation' => 'brand-new-secret-1',
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['This password reset token is invalid or has expired.'],
                $e->errors()['email'],
            );
        }

        $user->refresh();
        $this->assertTrue(Hash::check('password', $user->password));
    }
}
