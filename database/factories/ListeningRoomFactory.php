<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ListeningRoom;
use App\Models\Song;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListeningRoom>
 */
class ListeningRoomFactory extends Factory
{
    /**
     * A fresh, idle room — the state `POST /listening-rooms` creates when the
     * host had nothing playing.
     *
     * The code is drawn from the configured alphabet rather than
     * `Str::random(6)`, so a test that asserts on code shape is asserting on the
     * same rules the service applies.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $alphabet = (string) config('music.listening.code_alphabet');
        $length = (int) config('music.listening.code_length');

        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return [
            'room_code' => $code,
            'host_user_id' => User::factory(),
            'current_song_id' => null,
            'position_ms' => 0,
            'is_playing' => false,
            'position_at' => null,
            'playback_version' => 0,
            'ended_at' => null,
        ];
    }

    public function hostedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'host_user_id' => $user->getKey(),
        ]);
    }

    /**
     * A room mid-song.
     *
     * `position_at` defaults to now, which means the effective position is the
     * one passed in. A test that wants elapsed time simulated passes an earlier
     * instant instead of sleeping.
     */
    public function playing(Song $song, int $positionMs = 0, ?string $measuredAt = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_song_id' => $song->getKey(),
            'position_ms' => $positionMs,
            'is_playing' => true,
            'position_at' => $measuredAt ?? now(),
            'playback_version' => 1,
        ]);
    }

    public function paused(Song $song, int $positionMs = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_song_id' => $song->getKey(),
            'position_ms' => $positionMs,
            'is_playing' => false,
            'position_at' => now(),
            'playback_version' => 1,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ended_at' => now(),
            'is_playing' => false,
        ]);
    }
}
