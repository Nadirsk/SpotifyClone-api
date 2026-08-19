<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /testing/verify-bypass-phone` — the one unauthenticated test fixture.
 *
 * It exists so the browser suite can sign in by phone without `OtpService::send()`
 * billing a real SMS on every run (see that method: the bypass number keeps a
 * fixed code but the message is still sent, still costs a credit, and is capped at
 * three per five minutes).
 *
 * Being unauthenticated is unavoidable — it runs before any token exists — so what
 * keeps it safe is that it does exactly one thing for exactly one number. That is
 * what these tests pin, and they are the reason this file exists rather than the
 * endpoint being trusted on inspection:
 *
 * - it refuses every number except the configured bypass one, so it can never be
 *   pointed at a real listener's phone;
 * - it 404s outright when no bypass number is configured;
 * - it issues no token and creates no account, so it grants nothing beyond what
 *   knowing the bypass code already grants.
 */
final class BypassPhoneFixtureTest extends TestCase
{
    use RefreshDatabase;

    private const BYPASS = '9326431979';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.textsms.bypass_phone' => self::BYPASS]);
    }

    public function test_it_marks_the_bypass_phone_verified_without_sending_anything(): void
    {
        $this->postJson('/api/v1/testing/verify-bypass-phone')
            ->assertOk()
            ->assertJsonPath('success', true);

        $record = PhoneOtp::query()->where('phone', self::BYPASS)->first();

        $this->assertNotNull($record);
        $this->assertNotNull($record->verified_at, 'the row has to be verified, or login/phone still refuses it');
        $this->assertSame('signup', $record->type);
    }

    public function test_the_verified_row_is_enough_for_a_phone_login(): void
    {
        User::factory()->create(['phone' => self::BYPASS, 'email' => null]);

        $this->postJson('/api/v1/testing/verify-bypass-phone')->assertOk();

        /*
         | No `otp` in the body on purpose: `login/phone` does not check a code, it
         | checks that the number was verified recently. That is the whole reason
         | the fixture is sufficient.
         */
        $this->postJson('/api/v1/auth/login/phone', ['phone' => self::BYPASS, 'country_code' => '+91'])
            ->assertOk()
            ->assertJsonPath('data.user.phone', self::BYPASS)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_it_refuses_any_other_number(): void
    {
        $this->postJson('/api/v1/testing/verify-bypass-phone', ['phone' => '9000000001'])
            ->assertNotFound();

        $this->assertSame(
            0,
            PhoneOtp::query()->count(),
            'a refused request must not leave a verified row behind for anyone',
        );
    }

    public function test_it_is_unavailable_when_no_bypass_phone_is_configured(): void
    {
        config(['services.textsms.bypass_phone' => null]);

        $this->postJson('/api/v1/testing/verify-bypass-phone')->assertNotFound();

        $this->assertSame(0, PhoneOtp::query()->count());
    }

    public function test_it_issues_no_credentials_of_its_own(): void
    {
        $response = $this->postJson('/api/v1/testing/verify-bypass-phone')->assertOk();

        // Nothing token-shaped comes back: the caller still has to go through
        // `login/phone`, against an account that must already exist.
        $this->assertNull($response->json('data'));
        $this->assertSame(0, User::query()->count());
    }
}
