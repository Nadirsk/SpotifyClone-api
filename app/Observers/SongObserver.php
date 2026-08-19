<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CreditRole;
use App\Models\Song;
use App\Models\SongCredit;
use App\Services\Cache\CacheService;
use App\Services\Sync\CreditWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Keeps two things true whenever a song is written: the album's cached
 * tracklist is dropped, and the song's display artist has a credit row.
 *
 * ## The display-artist credit
 *
 * `song_credits` is maintained as a complete *superset* of `songs.artist_id` —
 * every song has a credit row for the artist it is labelled with, whether or not
 * a provider ever told us about credits at all. That invariant is what lets
 * {@see Song::scopeCreditedTo()} be a single indexed lookup on
 * `(artist_id, role)` instead of `artist_id = ? OR EXISTS (...)`.
 *
 * The difference is not academic. The OR form cannot use either index — MySQL
 * scans all 36,000 songs and runs the subquery per row — and measured 406ms for
 * the paginator's count plus 672ms for the page itself on the artist endpoint.
 * The superset form is two index lookups.
 *
 * Maintained here, at the write, rather than only in the sync path or the
 * backfill command: songs arrive from `catalog:bootstrap`, the crawler,
 * `LazySyncSearchJob` and model factories, and an invariant a query depends on
 * cannot hold only for the paths someone remembered. In particular a factory
 * that never touches a provider still gets the row, so a test's artist page is
 * not silently empty.
 *
 * `AlbumService::tracks()` caches under `album:tracks:{id}` for an hour and
 * nothing invalidated it, which made an empty album page *stay* empty long
 * after the data arrived: the crawler creates an album row when it discovers
 * it and fetches the tracklist later, so the first request lands in the gap,
 * caches `[]`, and serves that for the rest of the hour. Observed exactly that
 * way on "Gehra Hua (From Dhurandhar)" — one track in the table, `[]` on the
 * wire.
 *
 * An observer rather than a call inside `SyncService` because the album is
 * filled from several independent paths — `catalog:bootstrap`, the crawler's
 * album targets and `LazySyncSearchJob` all write songs — and every one of
 * them invalidates the same key. Hanging it off the write itself is what makes
 * that true for the next path as well as the three that exist today.
 * `writeEntity()` persists through `create()`/`save()`, so these events fire.
 */
final readonly class SongObserver
{
    public function __construct(
        private CacheService $cache,
    ) {}

    public function saved(Song $song): void
    {
        $this->ensureDisplayArtistCredit($song);

        /*
         | Both albums, not just the current one. Dedup regularly moves a
         | recording between album rows — the same JioSaavn track exists as a
         | soundtrack cut and as a single, and whichever syncs last claims it —
         | which leaves the album it *left* holding a tracklist that still
         | lists it.
         */
        $this->forgetAlbum($song->getOriginal('album_id'));
        $this->forgetAlbum($song->album_id);
    }

    public function deleted(Song $song): void
    {
        $this->forgetAlbum($song->album_id);
    }

    public function restored(Song $song): void
    {
        $this->forgetAlbum($song->album_id);
    }

    /**
     * Record the display artist as a `primary` credit, and drop the row for a
     * display artist the song no longer has.
     *
     * `insertOrIgnore` rather than a read-then-write: the row is normally
     * already there, the unique key on (song_id, artist_id, role) is what
     * decides, and a provider `primary` credit naming the same artist is the
     * same fact — first writer wins and the second is a no-op.
     *
     * The delete half matters because dedup does reassign `artist_id`: without
     * it the artist a song was moved *off* keeps it on their page for ever,
     * which is the mirror image of the stale-tracklist bug this observer already
     * exists for.
     *
     * But it is conditional, and has to be. There is no column marking which
     * `primary` rows this method seeded, and a provider genuinely publishes
     * `primary` credits of its own — for "Apna Bana Le" it names three people,
     * one of whom the sync had elected the display artist. Deleting on that
     * basis alone would destroy real credit data every time a display artist was
     * corrected.
     *
     * So the row is dropped only when the departing artist has no *other* credit
     * on the song. No other credit means the provider never mentioned them, so
     * the `primary` row can only have come from here and is now stale. Any other
     * credit means they really are involved, and their headline credit is
     * theirs to keep — the display name changing does not un-write it.
     *
     * A stale row that survives this is still not permanent: the next time
     * {@see CreditWriter} writes this song it replaces the
     * whole credit list from the provider payload, which is authoritative.
     */
    private function ensureDisplayArtistCredit(Song $song): void
    {
        $artistId = $song->artist_id;

        if (! is_string($artistId) || $artistId === '') {
            return;
        }

        $previous = $song->getOriginal('artist_id');

        if (is_string($previous) && $previous !== '' && $previous !== $artistId) {
            $creditedOtherwise = SongCredit::query()
                ->where('song_id', $song->getKey())
                ->where('artist_id', $previous)
                ->where('role', '!=', CreditRole::Primary->value)
                ->exists();

            if (! $creditedOtherwise) {
                SongCredit::query()
                    ->where('song_id', $song->getKey())
                    ->where('artist_id', $previous)
                    ->where('role', CreditRole::Primary->value)
                    ->delete();
            }
        }

        $now = Carbon::now();

        SongCredit::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'song_id' => $song->getKey(),
            'artist_id' => $artistId,
            'role' => CreditRole::Primary->value,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function forgetAlbum(mixed $albumId): void
    {
        if (! is_string($albumId) || $albumId === '') {
            return;
        }

        // `show` carries the album's own row, which reports a track count.
        $this->cache->forgetMany('album', ["tracks:{$albumId}", "show:{$albumId}"]);
    }
}
