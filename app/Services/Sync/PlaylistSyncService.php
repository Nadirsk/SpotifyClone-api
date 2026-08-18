<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\DTO\Providers\ProviderPlaylistData;
use App\Enums\PlaylistSource;
use App\Enums\PlaylistVisibility;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Provider;
use App\Models\ProviderPlaylistMapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Persists provider-curated playlists, alongside {@see SyncService} which owns
 * songs, artists and albums.
 *
 * Kept separate rather than folded into SyncService for one structural reason:
 * a playlist is the only synced entity whose identity includes an *ordered
 * collection* of other entities. Every method here has to reconcile a
 * tracklist as well as a row, which is a different problem from the
 * merge-attributes-and-checksum shape the other three share.
 *
 * Two rules are load-bearing:
 *
 * - **Only provider-owned rows are ever rewritten.** Track reconciliation
 *   deletes rows, and pointing that at a user playlist would silently destroy
 *   their work. Resolution is by provider mapping alone, so a title collision
 *   with a user playlist creates a separate row rather than adopting theirs.
 *
 * - **A track the catalog does not have yet is synced first.** JioSaavn hands
 *   over the playlist songs as full records in the same response, so they are
 *   persisted through SyncService before the tracklist is written. Skipping
 *   unknown songs instead would leave editorial playlists full of holes that
 *   nothing later fills in.
 */
final class PlaylistSyncService
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Persist one playlist and the page of tracks it arrived with.
     *
     * @param  bool  $replaceTracks  True on the first page of a walk, which clears the
     *                               existing tracklist before writing; later pages append.
     *                               This is how a track removed upstream stops being in ours —
     *                               an append-only reconcile could add and update but never forget.
     * @param  int  $positionOffset  Where this page's first track sits in the playlist.
     */
    public function sync(
        Provider $provider,
        ProviderPlaylistData $data,
        bool $replaceTracks = true,
        int $positionOffset = 0,
    ): ?Playlist {
        if (trim($data->title) === '' || trim($data->externalId) === '') {
            $this->logger->info('Playlist sync rejected an invalid record', [
                'provider' => $provider->api_name,
                'external_id' => $data->externalId,
            ]);

            return null;
        }

        /*
         | The songs are synced OUTSIDE the transaction below, on purpose.
         | Each SyncService::syncSong() opens its own transaction, and a
         | 50-track page nested inside one long playlist transaction would hold
         | row locks across half the catalog for its duration — artists and
         | albums included, since resolveArtist() writes those too. Doing it
         | first means the playlist transaction only touches playlist tables.
         */
        $songIds = $this->syncTracks($provider, $data);

        return DB::transaction(function () use ($provider, $data, $replaceTracks, $positionOffset, $songIds): Playlist {
            $playlist = $this->resolvePlaylist($provider, $data);

            $playlist->fill(array_filter([
                'title' => trim($data->title),
                'description' => $data->description,
                'cover_image' => $data->image,
            ], static fn (mixed $value): bool => $value !== null));

            $playlist->save();

            $this->writeTracks($playlist, $songIds, $replaceTracks, $positionOffset);
            $this->refreshCounts($playlist);
            $this->writeMapping($provider, $playlist, $data);

            return $playlist;
        });
    }

    /**
     * Persist this page's songs and return their local IDs, in playlist order.
     *
     * A song that fails validation is dropped rather than aborting the page:
     * an editorial playlist occasionally carries a track with no resolvable
     * artist, and losing that entry is much better than losing the playlist.
     *
     * @return list<string>
     */
    private function syncTracks(Provider $provider, ProviderPlaylistData $data): array
    {
        $songIds = [];

        foreach ($data->songs as $songData) {
            $song = $this->sync->syncSong($provider, $songData);

            if ($song !== null) {
                $songIds[] = (string) $song->getKey();
            }
        }

        return $songIds;
    }

    /**
     * Find the playlist this provider record maps to, or start a new one.
     *
     * Resolution is by mapping row only — never by title. Different editorial
     * playlists genuinely share a name across languages, and matching on title
     * would collapse them into one row that each sync overwrites with the
     * other's tracks. The provider's ID is the only stable identity a playlist
     * has.
     */
    private function resolvePlaylist(Provider $provider, ProviderPlaylistData $data): Playlist
    {
        $mapping = ProviderPlaylistMapping::query()
            ->with('playlist')
            ->where('provider_id', $provider->getKey())
            ->where('provider_playlist_id', $data->externalId)
            ->first();

        if ($mapping?->playlist !== null) {
            return $mapping->playlist;
        }

        return new Playlist([
            // No owner: an editorial playlist belongs to the provider, not a
            // person. The column is nullable precisely for this.
            'user_id' => null,
            'source' => $this->sourceFor($provider),
            'title' => trim($data->title),
            'slug' => $this->uniqueSlug($data->title),
            'visibility' => PlaylistVisibility::Public,
        ]);
    }

    /**
     * Map the provider onto a PlaylistSource, defaulting to JioSaavn.
     *
     * Only one provider serves playlists today. The lookup exists so adding a
     * second is an enum case rather than a hunt for hardcoded strings.
     */
    private function sourceFor(Provider $provider): PlaylistSource
    {
        return PlaylistSource::tryFrom((string) $provider->api_name) ?? PlaylistSource::JioSaavn;
    }

    /**
     * A slug no other playlist is already using.
     *
     * `playlists.slug` is indexed but not unique, so a duplicate would not
     * error — it would just make two playlists indistinguishable to any
     * slug-addressed route. Suffixed rather than deduplicated because, unlike
     * albums, two same-named playlists usually are different playlists.
     */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'playlist';
        }

        $slug = $base;
        $suffix = 2;

        while (Playlist::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;

            // Give up on a pathological collision run rather than spin.
            if ($suffix > 50) {
                return $base.'-'.Str::lower(Str::random(6));
            }
        }

        return $slug;
    }

    /**
     * Write this page's tracks at their playlist positions.
     *
     * `upsert` on (playlist_id, song_id) rather than a plain insert: the pair
     * is uniquely indexed, and the same song legitimately reappears when a
     * page is re-fetched after a retry. Position is updated too, so a
     * reordered playlist converges instead of keeping whichever order was
     * written first.
     *
     * @param  list<string>  $songIds
     */
    private function writeTracks(Playlist $playlist, array $songIds, bool $replaceTracks, int $positionOffset): void
    {
        if ($replaceTracks) {
            /*
             | Safe because this playlist is provider-owned by construction:
             | resolvePlaylist() only ever returns a mapped row, or a new one
             | it just built with a provider source. A user playlist cannot
             | reach this line.
             */
            PlaylistTrack::query()->where('playlist_id', $playlist->getKey())->delete();
        }

        if ($songIds === []) {
            return;
        }

        $now = Carbon::now();
        $rows = [];

        foreach ($songIds as $index => $songId) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'playlist_id' => (string) $playlist->getKey(),
                'song_id' => $songId,
                'position' => $positionOffset + $index,
                'added_at' => $now,
            ];
        }

        PlaylistTrack::query()->upsert($rows, ['playlist_id', 'song_id'], ['position']);
    }

    /**
     * Recompute the denormalized counters from the tracks actually stored.
     *
     * Derived from the join rather than from the provider's `songCount`,
     * which on a detail response reports the page size rather than the
     * playlist length (see JioSaavnAdapter::mapPlaylist()). Trusting it would
     * leave every long playlist claiming exactly 50 tracks.
     */
    private function refreshCounts(Playlist $playlist): void
    {
        $totals = DB::table('playlist_tracks')
            ->join('songs', 'songs.id', '=', 'playlist_tracks.song_id')
            ->where('playlist_tracks.playlist_id', $playlist->getKey())
            ->selectRaw('COUNT(*) as tracks, COALESCE(SUM(songs.duration), 0) as duration')
            ->first();

        $playlist->forceFill([
            'tracks_count' => (int) ($totals->tracks ?? 0),
            'total_duration' => (int) ($totals->duration ?? 0),
        ])->save();
    }

    /**
     * Create or refresh the provider mapping, mirroring
     * SyncService::writeMapping() — matched on either unique key the table
     * enforces, so a playlist arriving under a changed provider ID updates
     * rather than colliding.
     */
    private function writeMapping(Provider $provider, Playlist $playlist, ProviderPlaylistData $data): void
    {
        $mapping = ProviderPlaylistMapping::query()
            ->where('provider_id', $provider->getKey())
            ->where(function ($query) use ($data, $playlist): void {
                $query->where('provider_playlist_id', $data->externalId)
                    ->orWhere('playlist_id', $playlist->getKey());
            })
            ->first();

        $mapping ??= new ProviderPlaylistMapping;

        $mapping->fill([
            'provider_id' => $provider->getKey(),
            'playlist_id' => (string) $playlist->getKey(),
            'provider_playlist_id' => $data->externalId,
            'checksum' => $data->checksum(),
            'last_synced_at' => Carbon::now(),
        ])->save();
    }
}
