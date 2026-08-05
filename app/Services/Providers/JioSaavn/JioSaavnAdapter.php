<?php

declare(strict_types=1);

namespace App\Services\Providers\JioSaavn;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Services\Providers\AbstractProviderAdapter;

/**
 * JioSaavn's catalog, reached through a community-maintained JSON wrapper
 * (JioSaavn publishes no official public developer API). `base_url` is
 * therefore config, not a fixed constant — point it at a self-hosted mirror
 * of the wrapper if the shared public one is not trustworthy for production
 * traffic, per CLAUDE.md "Respect provider rate limits and API terms."
 *
 * Response shape quirks absorbed here:
 *
 * - every endpoint wraps its payload as `{ success, data }`; a false
 *   `success` is this provider's way of saying "no record", so the body has
 *   to be inspected like Deezer's inline `error` object;
 * - `id`-based lookups return the singular resource under `data` for albums
 *   and artists, but a list under `data` for songs;
 * - images and artist bio arrive as arrays of variants rather than a single
 *   URL/string, so the highest-quality entry is picked.
 */
final class JioSaavnAdapter extends AbstractProviderAdapter
{
    /**
     * JioSaavn exposes `playCount` as an unbounded string rather than a
     * bounded score. This ceiling is a rough heuristic — unlike Deezer's
     * documented `rank` scale, JioSaavn publishes no maximum — chosen so
     * that only genuinely viral tracks saturate the schema's 0–100 column.
     */
    private const PLAY_COUNT_CEILING = 50_000_000;

    public function key(): string
    {
        return 'jiosaavn';
    }

    /** Public reads: being switched on is the whole configuration. */
    protected function hasCredentials(): bool
    {
        return true;
    }

    /** @return list<ProviderSongData> */
    public function searchSongs(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search/songs', $query, $limit),
            fn (array $item): ?ProviderSongData => $this->mapSong($item),
        );
    }

    /** @return list<ProviderArtistData> */
    public function searchArtists(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search/artists', $query, $limit),
            fn (array $item): ?ProviderArtistData => $this->mapArtist($item),
        );
    }

    /** @return list<ProviderAlbumData> */
    public function searchAlbums(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search/albums', $query, $limit),
            fn (array $item): ?ProviderAlbumData => $this->mapAlbum($item),
        );
    }

    public function getSong(string $externalId): ?ProviderSongData
    {
        $payload = $this->fetch('/songs/'.rawurlencode($externalId));

        if ($payload === null) {
            return null;
        }

        $data = $this->dig($payload, 'data');
        $item = is_array($data) ? $this->dig($data, '0') : null;

        return is_array($item) ? $this->mapSong($item) : null;
    }

    public function getArtist(string $externalId): ?ProviderArtistData
    {
        $payload = $this->fetch('/artists/'.rawurlencode($externalId));
        $data = $payload === null ? null : $this->dig($payload, 'data');

        return is_array($data) ? $this->mapArtist($data) : null;
    }

    public function getAlbum(string $externalId): ?ProviderAlbumData
    {
        $payload = $this->fetch('/albums', ['id' => $externalId]);
        $data = $payload === null ? null : $this->dig($payload, 'data');

        return is_array($data) ? $this->mapAlbum($data) : null;
    }

    /** @return array<array-key, mixed>|null */
    private function search(string $path, string $query, int $limit): ?array
    {
        return $this->fetch($path, [
            'query' => $query,
            'page' => 0,
            'limit' => max(1, min(50, $limit)),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<array-key, mixed>|null
     */
    private function fetch(string $path, array $query = []): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $payload = $this->get($this->baseUrl().$path, $query);

        if ($payload === null) {
            return null;
        }

        // "No record" arrives as HTTP 200 with `success: false`, not a 404.
        if ($this->dig($payload, 'success') !== true) {
            $this->logFailure('provider_error', $this->baseUrl().$path, [
                'message' => $this->dig($payload, 'message'),
            ]);

            return null;
        }

        return $payload;
    }

    /**
     * @template TValue
     *
     * @param  array<array-key, mixed>|null  $payload
     * @param  callable(array<array-key, mixed>): (TValue|null)  $mapper
     * @return list<TValue>
     */
    private function mapAll(?array $payload, callable $mapper): array
    {
        $items = $this->dig($payload ?? [], 'data.results', []);

        if (! is_array($items)) {
            return [];
        }

        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = $mapper($item);

            if ($value !== null) {
                $mapped[] = $value;
            }
        }

        return $mapped;
    }

    /** @param array<array-key, mixed> $item */
    private function mapSong(array $item): ?ProviderSongData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $title = $this->str($this->dig($item, 'name'));

        if ($externalId === null || $title === null) {
            return null;
        }

        return new ProviderSongData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->str($this->dig($item, 'artists.primary.0.name')),
            album: $this->str($this->dig($item, 'album.name')),
            // Already seconds, like Deezer — no millisecond conversion needed.
            duration: $this->int($this->dig($item, 'duration')),
            genre: null,
            language: $this->str($this->dig($item, 'language')),
            releaseDate: $this->date($this->dig($item, 'releaseDate') ?? $this->dig($item, 'year')),
            image: $this->image($this->dig($item, 'image')),
            popularity: $this->playCount($this->dig($item, 'playCount')),
            isrc: null,
            previewUrl: null,
            externalUrl: $this->str($this->dig($item, 'url')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapArtist(array $item): ?ProviderArtistData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $name = $this->str($this->dig($item, 'name'));

        if ($externalId === null || $name === null) {
            return null;
        }

        return new ProviderArtistData(
            provider: $this->key(),
            externalId: $externalId,
            name: $name,
            genre: null,
            image: $this->image($this->dig($item, 'image')),
            bio: $this->str($this->dig($item, 'bio.0.text')),
            country: null,
            // Exposed as follower/fan counts rather than a bounded score.
            popularity: null,
            externalUrl: $this->str($this->dig($item, 'url')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapAlbum(array $item): ?ProviderAlbumData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $title = $this->str($this->dig($item, 'name'));

        if ($externalId === null || $title === null) {
            return null;
        }

        return new ProviderAlbumData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->str($this->dig($item, 'artists.primary.0.name')),
            genre: null,
            language: $this->str($this->dig($item, 'language')),
            releaseDate: $this->date($this->dig($item, 'releaseDate') ?? $this->dig($item, 'year')),
            image: $this->image($this->dig($item, 'image')),
            totalTracks: $this->int($this->dig($item, 'songCount')),
            popularity: $this->playCount($this->dig($item, 'playCount')),
            externalUrl: $this->str($this->dig($item, 'url')),
        );
    }

    /**
     * JioSaavn returns images as `[{quality: "50x50", url: "..."}, ...]`
     * ordered smallest first; the last entry is the highest resolution.
     */
    private function image(mixed $variants): ?string
    {
        if (! is_array($variants) || $variants === []) {
            return null;
        }

        $last = end($variants);

        return is_array($last) ? $this->str($this->dig($last, 'url')) : null;
    }

    /** Rescale JioSaavn's unbounded `playCount` onto the schema's 0–100 popularity. */
    private function playCount(mixed $playCount): ?int
    {
        $playCount = $this->int($playCount);

        return $playCount === null ? null : $this->popularity((int) round($playCount / self::PLAY_COUNT_CEILING * 100));
    }
}
