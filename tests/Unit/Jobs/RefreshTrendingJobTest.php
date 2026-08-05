<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\RefreshTrendingJob;
use App\Models\History;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Pins the exponential-decay trending formula (07_SYNC_ENGINE §9): every play
 * inside `music.trending.window_days` contributes `0.5 ^ (age_hours /
 * half_life_hours)`, so a recent burst outranks a long quiet tail, and
 * anything outside the window contributes nothing at all — including
 * resetting a score an earlier run had written.
 */
class RefreshTrendingJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'music.trending.window_days' => 7,
            'music.trending.half_life_hours' => 48,
        ]);
    }

    private function play(Song $song, User $user, Carbon $playedAt): History
    {
        return History::query()->create([
            'user_id' => $user->getKey(),
            'song_id' => $song->getKey(),
            'played_at' => $playedAt,
            'ms_played' => 180_000,
        ]);
    }

    private function runJob(): void
    {
        (new RefreshTrendingJob)->handle(app(LoggerInterface::class));
    }

    public function test_a_song_with_only_recent_plays_gets_a_positive_trending_score(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create(['trending_score' => 0]);

        // Well inside the window: right now.
        $this->play($song, $user, now());
        $this->play($song, $user, now()->subMinutes(10));

        // Well outside the 7-day window: must not count at all.
        $windowDays = (int) config('music.trending.window_days');
        $this->play($song, $user, now()->subDays($windowDays + 1));

        $this->runJob();

        $this->assertGreaterThan(0, $song->fresh()->trending_score);
    }

    public function test_plays_entirely_outside_the_window_reset_an_existing_score_to_zero(): void
    {
        $user = User::factory()->create();

        // Simulate a score written by an earlier run of the job.
        $song = Song::factory()->create(['trending_score' => 500]);

        $windowDays = (int) config('music.trending.window_days');
        $this->play($song, $user, now()->subDays($windowDays + 1));
        $this->play($song, $user, now()->subDays($windowDays + 5));

        $this->runJob();

        $this->assertSame(0, $song->fresh()->trending_score);
    }

    public function test_recent_plays_outrank_the_same_play_count_spread_further_in_the_past(): void
    {
        $user = User::factory()->create();

        $recentSong = Song::factory()->create(['trending_score' => 0]);
        $oldSong = Song::factory()->create(['trending_score' => 0]);

        // Same total play count (5 each), very different recency mix.
        for ($i = 0; $i < 5; $i++) {
            $this->play($recentSong, $user, now()->subMinutes($i));
            // Six days back: inside the 7-day window, but three half-lives
            // (48h each) removed from now, so heavily decayed.
            $this->play($oldSong, $user, now()->subDays(6)->subMinutes($i));
        }

        $this->runJob();

        $recentScore = $recentSong->fresh()->trending_score;
        $oldScore = $oldSong->fresh()->trending_score;

        $this->assertGreaterThan(0, $oldScore, 'The older plays are still inside the window and should contribute something.');
        $this->assertGreaterThan(
            $oldScore,
            $recentScore,
            'Five plays clustered right now must outrank five plays of the same song six days back.',
        );
    }
}
