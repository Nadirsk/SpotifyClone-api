<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Recomputes `trending_score` for songs, artists and albums from the `history`
 * table (07_SYNC_ENGINE §9, config `music.trending`).
 *
 * ## The score
 *
 * Every play inside the window contributes `0.5 ^ (age_hours / half_life_hours)`.
 * A play right now is worth 1; at one half-life, 0.5; at two, 0.25. Summed per
 * entity, that makes a short recent burst outrank a long quiet tail — which is
 * what "trending" means, as opposed to "popular", which `play_count` already
 * measures.
 *
 * The decay is evaluated in SQL rather than in PHP because the alternative is
 * pulling every play row in the window into memory to add up floats.
 *
 * ## Why the scores are integers
 *
 * The `trending_score` columns are `unsignedInteger`, so the weighted sum is
 * multiplied by SCORE_SCALE and rounded — fixed-point in an integer column.
 * The scale lives here rather than in config/music.php because it is an
 * encoding detail of the column, not something an operator should tune;
 * changing it would silently rescale every stored score. `window_days` and
 * `half_life_hours` are the tunables, and those are in config.
 *
 * ## Writes
 *
 * Scores are written with two bulk statements per table — one reset, one
 * `CASE` update — rather than a save() per row. At a few thousand trending
 * entities the row-by-row version would be tens of thousands of queries every
 * fifteen minutes.
 *
 * Runs on `default`, not `sync`: it touches no provider and must not queue
 * behind a long provider sync, since it is the freshest-data job we have.
 */
final class RefreshTrendingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 07_SYNC_ENGINE §10. */
    public int $tries = 5;

    /** Fixed-point multiplier: one play happening right now scores 1000. */
    private const SCORE_SCALE = 1000;

    /** Rows per bulk UPDATE. Keeps the generated CASE statement inside MySQL's packet limit. */
    private const UPDATE_CHUNK = 500;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * Short backoff: the job runs every fifteen minutes, so a retry that waits
     * longer than that is pointless — the next scheduled run supersedes it.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 30, 60, 120];
    }

    public function handle(LoggerInterface $logger): void
    {
        $windowDays = max(1, (int) config('music.trending.window_days', 7));
        $halfLifeHours = max(1, (int) config('music.trending.half_life_hours', 48));

        $now = Carbon::now();
        $since = $now->copy()->subDays($windowDays);
        $halfLifeSeconds = $halfLifeHours * 3600;

        $songScores = $this->scores('song_id', $since, $now, $halfLifeSeconds);
        $artistScores = $this->scores('artist_id', $since, $now, $halfLifeSeconds);
        $albumScores = $this->scores('album_id', $since, $now, $halfLifeSeconds);

        $this->apply('songs', $songScores);
        $this->apply('artists', $artistScores);
        $this->apply('albums', $albumScores);

        $logger->info('Trending scores refreshed', [
            'window_days' => $windowDays,
            'half_life_hours' => $halfLifeHours,
            'songs' => count($songScores),
            'artists' => count($artistScores),
            'albums' => count($albumScores),
        ]);
    }

    /**
     * Weighted play totals keyed by entity ID.
     *
     * `song_id` reads straight off `history`; the other two join through
     * `songs`, because a play is only ever recorded against a song and an
     * artist's or album's trend is the sum of its songs'.
     *
     * @param  'song_id'|'artist_id'|'album_id'  $groupBy
     * @return array<string, int>
     */
    private function scores(string $groupBy, Carbon $since, Carbon $now, int $halfLifeSeconds): array
    {
        $query = DB::table('history')
            ->where('history.played_at', '>=', $since)
            ->selectRaw(
                'SUM(POW(0.5, TIMESTAMPDIFF(SECOND, history.played_at, ?) / ?)) as weighted_plays',
                [$now, $halfLifeSeconds],
            );

        if ($groupBy === 'song_id') {
            $query->addSelect('history.song_id as entity_id')->groupBy('history.song_id');
        } else {
            $query->join('songs', 'songs.id', '=', 'history.song_id')
                // Singles have no album; a null group would score nothing useful.
                ->whereNotNull("songs.{$groupBy}")
                ->addSelect("songs.{$groupBy} as entity_id")
                ->groupBy("songs.{$groupBy}");
        }

        $scores = [];

        foreach ($query->get() as $row) {
            $score = (int) round(((float) $row->weighted_plays) * self::SCORE_SCALE);

            // Plays old enough to decay below one unit are not worth a write.
            if ($score > 0) {
                $scores[(string) $row->entity_id] = $score;
            }
        }

        return $scores;
    }

    /**
     * Write the scores in bulk.
     *
     * Everything is reset first so an entity that has fallen out of the window
     * drops to zero instead of keeping last week's score for ever. The reset is
     * narrowed to rows that actually hold a score, which on a large catalog is
     * a small fraction of it.
     *
     * @param  'songs'|'artists'|'albums'  $table
     * @param  array<string, int>  $scores
     */
    private function apply(string $table, array $scores): void
    {
        DB::table($table)->where('trending_score', '>', 0)->update(['trending_score' => 0]);

        if ($scores === []) {
            return;
        }

        foreach (array_chunk($scores, self::UPDATE_CHUNK, preserve_keys: true) as $chunk) {
            $cases = '';
            $bindings = [];

            foreach ($chunk as $id => $score) {
                $cases .= ' WHEN ? THEN ?';
                $bindings[] = $id;
                $bindings[] = $score;
            }

            $ids = array_keys($chunk);
            $bindings = array_merge($bindings, $ids);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));

            /*
             | $table comes from this class's own call sites, never from input,
             | and every value is bound — the only interpolation is the table
             | name and the placeholder run.
             */
            DB::update(
                "UPDATE `{$table}` SET `trending_score` = CASE `id`{$cases} END WHERE `id` IN ({$placeholders})",
                $bindings,
            );
        }
    }
}
