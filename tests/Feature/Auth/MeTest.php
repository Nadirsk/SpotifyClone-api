<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MeTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/auth/me';

    public function test_me_returns_the_authenticated_users_data(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'country' => 'IN',
            'language' => 'en',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Request successful',
            ])
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.country', 'IN')
            ->assertJsonMissingPath('data.password');
    }

    public function test_me_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson(self::ENDPOINT);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated',
            ]);
    }
}
