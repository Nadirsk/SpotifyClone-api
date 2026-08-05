<?php

declare(strict_types=1);

namespace App\Services\Providers\Deezer;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Services\Providers\AbstractProviderAdapter;

/**
 * Deezer's public catalog API.
 *
 * No authentication for catalog reads, so the only credential-shaped thing here
 * is the enabled flag. Two Deezer quirks are absorbed in this class:
 *
 * - errors arrive as HTTP 200 with an `error` object in the body, so a status
 *   check alone is not enough to know a call succeeded;
 * - popularity is exposed as `rank` on a roughly 0–1,000,000 scale, which is
 *   rescaled to the 0–100 the catalog stores.
 */
final class DeezerAdapter extends AbstractProviderAdapter
{
    /** Deezer's `rank` saturates around this value for the most popular tracks. */
    private const RANK_CEILING = 1_000_000;

    public function key(): string
    {
        return 'deezer';
    }

    /** Public API: being switched on is the whole configuration. */
    protected function hasCredentials(): bool
    {
        return true;
    }

    /** @return list<ProviderSongData> */
    public function searchSongs(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search', $query, $limit),
            fn (array $item): ?ProviderSongData => $this->mapSong($item),
        );
    }

    /** @return list<ProviderArtistData> */
    public function searchArtists(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search/artist', $query, $limit),
            fn (array $item): ?ProviderArtistData => $this->mapArtist($item),
        );
    }

    /** @return list<ProviderAlbumData> */
    public function searchAlbums(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search/album', $query, $limit),
            fn (array $item): ?ProviderAlbumData => $this->mapAlbum($item),
        );
    }

    public function getSong(string $externalId): ?ProviderSongData
    {
        $payload = $this->fetch('/track/'.rawurlencode($externalId));

        return $payload === null ? null : $this->mapSong($payload);
    }

    public function getArtist(string $externalId): ?ProviderArtistData
    {
        $payload = $this->fetch('/artist/'.rawurlencode($externalId));

        return $payload === null ? null : $this->mapArtist($payload);
    }

    public function getAlbum(string $externalId): ?ProviderAlbumData
    {
        $payload = $this->fetch('/album/'.rawurlencode($externalId));

        return $payload === null ? null : $this->mapAlbum($payload);
    }

    /** @return array<array-key, mixed>|null */
    private function search(string $path, string $query, int $limit): ?array
    {
        return $this->fetch($path, [
            'q' => $query,
            'limit' => max(1, min(100, $limit)),
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

        // Deezer signals "not found" and "quota exceeded" alike with HTTP 200
        // plus an error object, so the body has to be inspected.
        if (isset($payload['error'])) {
            $this->logFailure('provider_error', $this->baseUrl().$path, [
                'code' => $this->dig($payload, 'error.code'),
                'type' => $this->dig($payload, 'error.type'),
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
        $items = $this->dig($payload ?? [], 'data', []);

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
        $title = $this->str($this->dig($item, 'title'));

        if ($externalId === null || $title === null) {
            return null;
        }

        return new ProviderSongData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->str($this->dig($item, 'artist.name')),
            album: $this->str($this->dig($item, 'album.title')),
            // Already seconds, unlike Spotify and Apple.
            duration: $this->int($this->dig($item, 'duration')),
            // Only the full /track and /album resources carry a genre.
            genre: $this->str($this->dig($item, 'genres.data.0.name')),
            language: null,
            releaseDate: $this->date($this->dig($item, 'release_date')),
            image: $this->str($this->dig($item, 'album.cover_xl'))
                ?? $this->str($this->dig($item, 'album.cover_big'))
                ?? $this->str($this->dig($item, 'album.cover')),
            popularity: $this->rank($this->dig($item, 'rank')),
            isrc: $this->str($this->dig($item, 'isrc')),
            previewUrl: $this->str($this->dig($item, 'preview')),
            externalUrl: $this->str($this->dig($item, 'link')),
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
            image: $this->str($this->dig($item, 'picture_xl'))
                ?? $this->str($this->dig($item, 'picture_big'))
                ?? $this->str($this->dig($item, 'picture')),
            bio: null,
            country: null,
            // Deezer exposes fan counts rather than a bounded score.
            popularity: null,
            externalUrl: $this->str($this->dig($item, 'link')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapAlbum(array $item): ?ProviderAlbumData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $title = $this->str($this->dig($item, 'title'));

        if ($externalId === null || $title === null) {
            return null;
        }

        return new ProviderAlbumData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->str($this->dig($item, 'artist.name')),
            genre: $this->str($this->dig($item, 'genres.data.0.name')),
            language: null,
            releaseDate: $this->date($this->dig($item, 'release_date')),
            image: $this->str($this->dig($item, 'cover_xl'))
                ?? $this->str($this->dig($item, 'cover_big'))
                ?? $this->str($this->dig($item, 'cover')),
            totalTracks: $this->int($this->dig($item, 'nb_tracks')),
            popularity: null,
            externalUrl: $this->str($this->dig($item, 'link')),
        );
    }

    /** Rescale Deezer's unbounded `rank` onto the schema's 0–100 popularity. */
    private function rank(mixed $rank): ?int
    {
        $rank = $this->int($rank);

        return $rank === null ? null : $this->popularity((int) round($rank / self::RANK_CEILING * 100));
    }
}
