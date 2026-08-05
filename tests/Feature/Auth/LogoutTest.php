<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/auth/logout';

    /**
     * Logout must revoke exactly the token that authenticated the request,
     * not every token the user holds — signing out of a phone must not sign
     * the laptop out too. This deliberately uses a real bearer token rather
     * than Sanctum::actingAs(): actingAs() fakes the current-token object
     * instead of persisting one, so it cannot demonstrate a real row
     * disappearing from `personal_access_tokens`.
     */
    public function test_logout_revokes_only_the_token_used_for_the_request(): void
    {
        $user = User::factory()->create();

        $tokenA = $user->createToken('api');
        $tokenB = $user->createToken('api');

        $tokenAId = $tokenA->accessToken->getKey();
        $tokenBId = $tokenB->accessToken->getKey();

        $response = $this->withHeader('Authorization', 'Bearer '.$tokenA->plainTextToken)
            ->postJson(self::ENDPOINT);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully',
                'data' => null,
            ]);

        $this->assertNull(PersonalAccessToken::find($tokenAId));
        $this->assertNotNull(PersonalAccessToken::find($tokenBId));
    }

    public function test_logout_without_a_token_returns_401(): void
    {
        $response = $this->postJson(self::ENDPOINT);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated',
            ]);
    }
}
