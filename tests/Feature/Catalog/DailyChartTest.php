<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\History;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GET /api/v1/trending/songs?window=today — the daily chart.
 *
 * The home page has always had a "Top songs today" shelf, and it was always
 * filled from the rolling trending list: a 7-day sum with a 48-hour half-life,
 * which barely moves between one day and the next. So the shelf showed
 * yesterday's ranking all morning and called it today's.
 *
 * These pin the three things that makes it a *daily* chart: it counts only
 * today's plays, it ranks by how many, and it declines to answer at all when
 * the day is too thin to rank — which is what lets the caller fall back to the
 * weekly list under a weekly heading instead of mislabelling it.
 */
final class DailyChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // One play is enough to be a chart here; the "too thin" threshold gets
        // its own test below, which sets it explicitly.
        config(['music.trending.min_today' => 1]);
    }

    public function test_the_daily_chart_counts_only_todays_plays(): void
    {
        $today = Song::factory()->create();
        $yesterday = Song::factory()->create();

        $this->play($today, Carbon::today()->addHours(9));
        $this->play($yesterday, Carbon::yesterday()->addHours(9));
        $this->play($yesterday, Carbon::yesterday()->addHours(10));

        $response = $this->getJson('/api/v1/trending/songs?window=today');

        $response->assertOk()->assertJsonPath('message', 'Top songs today');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($today->id, $ids);
        $this->assertNotContains(
            $yesterday->id,
            $ids,
            'a song played only yesterday is not in today\'s chart, however many times it was played',
        );
    }

    public function test_the_daily_chart_ranks_by_play_count(): void
    {
        $most = Song::factory()->create();
        $fewer = Song::factory()->create();

        foreach (range(1, 3) as $hour) {
            $this->play($most, Carbon::today()->addHours($hour));
        }

        $this->play($fewer, Carbon::today()->addHour());

        $ids = collect($this->getJson('/api/v1/trending/songs?window=today')->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$most->id, $fewer->id], $ids);
    }

    public function test_a_skip_does_not_count_toward_the_daily_chart(): void
    {
        $skipped = Song::factory()->create(['duration' => 300]);
        $played = Song::factory()->create(['duration' => 300]);

        // Under the listen threshold: a skip, not a play. Rows written before
        // the threshold existed report no duration at all and still count, which
        // is why this has to be an explicit, too-small value rather than null.
        $this->play($skipped, Carbon::today()->addHour(), msPlayed: 3_000);
        $this->play($played, Carbon::today()->addHour(), msPlayed: 45_000);

        $ids = collect($this->getJson('/api/v1/trending/songs?window=today')->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$played->id], $ids);
    }

    public function test_a_guest_play_counts_toward_the_daily_chart(): void
    {
        $song = Song::factory()->create();

        History::query()->create([
            'user_id' => null,
            'session_id' => 'a-signed-out-browser',
            'song_id' => $song->getKey(),
            'played_at' => Carbon::today()->addHours(2),
            'ms_played' => 60_000,
        ]);

        $ids = collect($this->getJson('/api/v1/trending/songs?window=today')->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$song->id], $ids, 'listening without an account is still listening');
    }

    public function test_a_day_too_thin_to_rank_returns_nothing_rather_than_a_weekly_list(): void
    {
        config(['music.trending.min_today' => 5]);

        $song = Song::factory()->create(['trending_score' => 99_999]);
        $this->play($song, Carbon::today()->addHour());

        $response = $this->getJson('/api/v1/trending/songs?window=today');

        $response->assertOk();
        $this->assertSame(
            [],
            $response->json('data'),
            'two plays is not a chart; answering with the weekly list here is the bug this endpoint exists to fix',
        );
    }

    public function test_the_default_window_is_still_the_rolling_trending_list(): void
    {
        $ceiling = (int) (Song::query()->max('trending_score') ?? 0);
        $trending = Song::factory()->create(['trending_score' => $ceiling + 500]);

        // Played today, but with no trending score — it must not displace the
        // trending song when the daily window was not asked for.
        $this->play(Song::factory()->create(['trending_score' => 0]), Carbon::today()->addHour());

        $response = $this->getJson('/api/v1/trending/songs?limit=1');

        $response->assertOk()->assertJsonPath('message', 'Trending songs');
        $this->assertSame($trending->id, $response->json('data.0.id'));
    }

    private function play(Song $song, Carbon $at, ?int $msPlayed = null): void
    {
        History::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'song_id' => $song->getKey(),
            'played_at' => $at,
            'ms_played' => $msPlayed,
        ]);
    }
}
