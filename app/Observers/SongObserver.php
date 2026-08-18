<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Song;
use App\Services\Cache\CacheService;

/**
 * Drops an album's cached tracklist whenever a song joins, leaves or changes
 * on it.
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

    private function forgetAlbum(mixed $albumId): void
    {
        if (! is_string($albumId) || $albumId === '') {
            return;
        }

        // `show` carries the album's own row, which reports a track count.
        $this->cache->forgetMany('album', ["tracks:{$albumId}", "show:{$albumId}"]);
    }
}
