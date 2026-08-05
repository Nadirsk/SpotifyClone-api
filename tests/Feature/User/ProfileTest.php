<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_the_authenticated_users_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Jamie Rivera',
            'country' => 'US',
            'language' => 'en',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/profile');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.name', 'Jamie Rivera');
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonMissingPath('data.password');
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/profile')->assertStatus(401);
    }

    public function test_update_changes_name_country_and_language(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'country' => 'US',
            'language' => 'en',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/profile', [
            'name' => 'New Name',
            'country' => 'FR',
            'language' => 'fr',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $response->assertJsonPath('data.country', 'FR');
        $response->assertJsonPath('data.language', 'fr');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'country' => 'FR',
            'language' => 'fr',
        ]);
    }

    public function test_update_rejects_a_country_that_is_not_a_two_letter_code(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/profile', ['country' => 'USA']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('country');
    }

    public function test_update_requires_authentication(): void
    {
        $this->putJson('/api/v1/profile', ['name' => 'Nope'])->assertStatus(401);
    }

    public function test_avatar_upload_rejects_a_non_image_file(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $response = $this->putJson('/api/v1/profile/avatar', ['avatar' => $file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('avatar');
    }

    public function test_avatar_upload_rejects_a_file_over_the_size_limit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // UpdateAvatarRequest caps at 2048 KB; 3000 KB must be rejected.
        $file = UploadedFile::fake()->image('avatar.jpg')->size(3000);

        $response = $this->putJson('/api/v1/profile/avatar', ['avatar' => $file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('avatar');
    }

    public function test_avatar_upload_accepts_a_valid_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('avatar.png', 200, 200);

        $response = $this->putJson('/api/v1/profile/avatar', ['avatar' => $file]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertNotNull($response->json('data.avatar'));
    }

    public function test_destroy_soft_deletes_the_account_and_the_old_token_can_no_longer_authenticate(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/profile');

        $response->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Illuminate\Auth\RequestGuard caches the user it resolves for the
        // lifetime of the guard instance. The in-process test client reuses
        // one application container across both calls in this test, so
        // without forgetting the cached guard here, this second request would
        // silently reuse the first request's already-authenticated user
        // instead of re-resolving the (now deleted) token — a test-harness
        // artifact only, since real requests never share a guard instance.
        $this->app['auth']->forgetGuards();

        // The same bearer token must be rejected now that the account is gone.
        $reauth = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/profile');

        $reauth->assertStatus(401);
    }

    public function test_destroy_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/profile')->assertStatus(401);
    }
}
