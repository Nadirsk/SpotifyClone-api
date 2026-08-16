<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Song;
use App\Services\Catalog\SoundtrackParser;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Backfills `songs.film_title` / `albums.film_title` from titles already in the
 * catalog.
 *
 * The sync path parses new rows as they arrive (`SyncService`), so this is only
 * needed for content synced before the column existed — and as a repair after
 * `SoundtrackParser`'s pattern is ever widened. Idempotent: re-running it over
 * an already-parsed catalog rewrites the same values.
 *
 * `php artisan catalog:parse-soundtracks [--fresh]`
 *
 * `--fresh` also clears rows whose title no longer parses, which is what makes
 * the command a repair tool rather than an append-only one. Without it, a film
 * title that a narrowed pattern should no longer match would survive forever.
 */
final class ParseSoundtracks extends Command
{
    protected $signature = 'catalog:parse-soundtracks
        {--fresh : Also clear film titles whose source title no longer parses}
        {--chunk=500 : Rows loaded per batch}';

    protected $description = 'Derive film/soundtrack titles from song and album titles';

    public function handle(SoundtrackParser $parser): int
    {
        $chunk = max(50, (int) $this->option('chunk'));
        $fresh = (bool) $this->option('fresh');

        $songs = $this->backfill(Song::query()->getModel()::query(), $parser, $chunk, $fresh, 'songs');
        $albums = $this->backfill(Album::query()->getModel()::query(), $parser, $chunk, $fresh, 'albums');

        $this->newLine();
        $this->info("Songs tagged: {$songs}. Albums tagged: {$albums}.");

        /*
         | An album's own title rarely carries the credit — the *songs* do. So
         | after the direct parse, fill an album's film from the film its
         | tracks agree on. Done second so a direct parse always wins.
         */
        $inherited = $this->inheritAlbumFilmsFromTracks($fresh);
        $this->info("Albums tagged from their tracklist: {$inherited}.");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Song|Album>  $query
     */
    private function backfill(
        Builder $query,
        SoundtrackParser $parser,
        int $chunk,
        bool $fresh,
        string $label,
    ): int {
        $tagged = 0;

        $this->line("Parsing {$label}…");

        $query
            // Without `--fresh` only rows that have never been tagged are
            // touched, which keeps a re-run cheap on a large catalog.
            ->when(! $fresh, static fn ($builder) => $builder->whereNull('film_title'))
            ->chunkById($chunk, function (EloquentCollection $rows) use ($parser, $fresh, &$tagged): void {
                foreach ($rows as $row) {
                    $film = $parser->filmFrom($row->title);

                    if ($film === null && ! $fresh) {
                        continue;
                    }

                    if ($row->film_title === $film) {
                        continue;
                    }

                    // Timestamps left alone: this derives a column from data
                    // that did not change, and bumping `updated_at` across the
                    // whole catalog would make every sync freshness check lie.
                    $row->timestamps = false;
                    $row->forceFill(['film_title' => $film])->save();

                    if ($film !== null) {
                        $tagged++;
                    }
                }
            });

        return $tagged;
    }

    /**
     * Give an album the film its tracks belong to, when they agree unanimously.
     *
     * Unanimity is the bar on purpose: a compilation like "Bollywood Love Hits"
     * legitimately contains songs from a dozen films, and tagging it with
     * whichever one happens to be most common would put a compilation into a
     * film's page as if it were its soundtrack.
     */
    private function inheritAlbumFilmsFromTracks(bool $fresh): int
    {
        $updated = 0;

        Album::query()
            ->when(! $fresh, static fn ($builder) => $builder->whereNull('film_title'))
            ->whereHas('songs', static fn ($builder) => $builder->whereNotNull('film_title'))
            ->with('songs:id,album_id,film_title')
            ->chunkById(200, function (EloquentCollection $albums) use (&$updated): void {
                foreach ($albums as $album) {
                    $films = $album->songs
                        ->pluck('film_title')
                        ->filter()
                        ->unique()
                        ->values();

                    // Exactly one film across the whole tracklist, and every
                    // track accounted for.
                    if ($films->count() !== 1 || $album->songs->whereNull('film_title')->isNotEmpty()) {
                        continue;
                    }

                    if ($album->film_title === $films->first()) {
                        continue;
                    }

                    $album->timestamps = false;
                    $album->forceFill(['film_title' => $films->first()])->save();
                    $updated++;
                }
            });

        return $updated;
    }
}
