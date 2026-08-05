<?php

declare(strict_types=1);

namespace App\Services\Providers\LastFm;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Services\Providers\AbstractProviderAdapter;
use RuntimeException;

/**
 * Last.fm 2.0 API.
 *
 * One endpoint, an `method` parameter and the API key in the query string —
 * which is precisely why AbstractProviderAdapter redacts sensitive query
 * parameters before logging a URL. Nothing here may log a raw request URL.
 *
 * Last.fm has no per-record ID of its own for tracks and albums: resources are
 * addressed by artist+title. The adapter therefore synthesises a stable
 * external ID from those names (`artist||title`) so the mapping tables have
 * something to key on. Callers must treat that ID as opaque, exactly as they
 * treat a Spotify ID.
 */
final class LastFmAdapter extends AbstractProviderAdapter
{
    /** Separator for the synthetic artist+title identifiers. Chosen to not occur in real names. */
    private const ID_SEPARATOR = '||';

    public function key(): string
    {
        return 'lastfm';
    }

    protected function hasCredentials(): bool
    {
        return $this->str($this->setting('api_key')) !== null;
    }

    public function authenticate(): void
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException(
                'Last.fm is not configured. Set LASTFM_ENABLED=true and LASTFM_API_KEY.'
            );
        }
    }

    /** @return list<ProviderSongData> */
    public function searchSongs(string $query, int $limit): array
    {
        $payload = $this->call('track.search', ['track' => $query, 'limit' => $this->limit($limit)]);

        return $this->mapAll(
            $payload,
            'results.trackmatches.track',
            fn (array $item): ?ProviderSongData => $this->mapSong($item),
        );
    }

    /** @return list<ProviderArtistData> */
    public function searchArtists(string $query, int $limit): array
    {
        $payload = $this->call('artist.search', ['artist' => $query, 'limit' => $this->limit($limit)]);

        return $this->mapAll(
            $payload,
            'results.artistmatches.artist',
            fn (array $item): ?ProviderArtistData => $this->mapArtist($item),
        );
    }

    /** @return list<ProviderAlbumData> */
    public function searchAlbums(string $query, int $limit): array
    {
        $payload = $this->call('album.search', ['album' => $query, 'limit' => $this->limit($limit)]);

        return $this->mapAll(
            $payload,
            'results.albummatches.album',
            fn (array $item): ?ProviderAlbumData => $this->mapAlbum($item),
        );
    }

    public function getSong(string $externalId): ?ProviderSongData
    {
        [$artist, $title] = $this->splitId($externalId);

        if ($artist === null || $title === null) {
            return null;
        }

        $payload = $this->call('track.getInfo', ['artist' => $artist, 'track' => $title]);
        $track = $this->dig($payload ?? [], 'track');

        return is_array($track) ? $this->mapSong($track) : null;
    }

    public function getArtist(string $externalId): ?ProviderArtistData
    {
        $payload = $this->call('artist.getInfo', ['artist' => $externalId]);
        $artist = $this->dig($payload ?? [], 'artist');

        return is_array($artist) ? $this->mapArtist($artist) : null;
    }

    public function getAlbum(string $externalId): ?ProviderAlbumData
    {
        [$artist, $title] = $this->splitId($externalId);

        if ($artist === null || $title === null) {
            return null;
        }

        $payload = $this->call('album.getInfo', ['artist' => $artist, 'album' => $title]);
        $album = $this->dig($payload ?? [], 'album');

        return is_array($album) ? $this->mapAlbum($album) : null;
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array<array-key, mixed>|null
     */
    private function call(string $method, array $params): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $payload = $this->get((string) $this->setting('base_url'), array_merge($params, [
            'method' => $method,
            'api_key' => (string) $this->setting('api_key'),
            'format' => 'json',
        ]));

        if ($payload === null) {
            return null;
        }

        // Last.fm reports application errors with HTTP 200 and an `error` code.
        if (isset($payload['error'])) {
            $this->logFailure('provider_error', (string) $this->setting('base_url'), [
                'method' => $method,
                'code' => $payload['error'],
            ]);

            return null;
        }

        return $payload;
    }

    private function limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }

    /**
     * @template TValue
     *
     * @param  array<array-key, mixed>|null  $payload
     * @param  callable(array<array-key, mixed>): (TValue|null)  $mapper
     * @return list<TValue>
     */
    private function mapAll(?array $payload, string $path, callable $mapper): array
    {
        $items = $this->dig($payload ?? [], $path, []);

        if (! is_array($items)) {
            return [];
        }

        /*
         | A single match is returned as one object rather than a list of one.
         | Detecting that by a string key is more robust than counting, because
         | Last.fm's matches are always plain lists when there are several.
         */
        if (isset($items['name']) || isset($items['title'])) {
            $items = [$items];
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
        $title = $this->str($this->dig($item, 'name'));
        $artist = $this->str($this->dig($item, 'artist.name')) ?? $this->str($this->dig($item, 'artist'));

        if ($title === null || $artist === null) {
            return null;
        }

        // track.getInfo gives milliseconds; track.search gives nothing at all.
        $duration = $this->int($this->dig($item, 'duration'));

        return new ProviderSongData(
            provider: $this->key(),
            externalId: $this->makeId($artist, $title),
            title: $title,
            artist: $artist,
            album: $this->str($this->dig($item, 'album.title')),
            duration: $duration === null || $duration === 0 ? null : (int) round($duration / 1000),
            genre: $this->str($this->dig($item, 'toptags.tag.0.name')),
            language: null,
            releaseDate: null,
            image: $this->image($this->dig($item, 'album.image')) ?? $this->image($this->dig($item, 'image')),
            // Last.fm publishes listener and scrobble counts, not a bounded score;
            // rescaling them would invent precision the data does not have.
            popularity: null,
            isrc: null,
            previewUrl: null,
            externalUrl: $this->str($this->dig($item, 'url')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapArtist(array $item): ?ProviderArtistData
    {
        $name = $this->str($this->dig($item, 'name'));

        if ($name === null) {
            return null;
        }

        return new ProviderArtistData(
            provider: $this->key(),
            // Last.fm addresses artists by name, so the name is the external ID.
            externalId: $name,
            name: $name,
            genre: $this->str($this->dig($item, 'tags.tag.0.name')),
            image: $this->image($this->dig($item, 'image')),
            bio: $this->str($this->dig($item, 'bio.summary')),
            country: null,
            popularity: null,
            externalUrl: $this->str($this->dig($item, 'url')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapAlbum(array $item): ?ProviderAlbumData
    {
        $title = $this->str($this->dig($item, 'name'));
        $artist = $this->str($this->dig($item, 'artist.name')) ?? $this->str($this->dig($item, 'artist'));

        if ($title === null || $artist === null) {
            return null;
        }

        $tracks = $this->dig($item, 'tracks.track');

        return new ProviderAlbumData(
            provider: $this->key(),
            externalId: $this->makeId($artist, $title),
            title: $title,
            artist: $artist,
            genre: $this->str($this->dig($item, 'tags.tag.0.name')),
            language: null,
            releaseDate: null,
            image: $this->image($this->dig($item, 'image')),
            totalTracks: is_array($tracks) ? count($tracks) : null,
            popularity: null,
            externalUrl: $this->str($this->dig($item, 'url')),
        );
    }

    /**
     * Last.fm images come as a list of `{'#text': url, size: small|medium|...}`.
     * Prefer the largest available.
     */
    private function image(mixed $images): ?string
    {
        if (! is_array($images)) {
            return null;
        }

        $bySize = [];

        foreach ($images as $image) {
            if (is_array($image)) {
                $bySize[(string) ($image['size'] ?? '')] = $this->str($image['#text'] ?? null);
            }
        }

        foreach (['mega', 'extralarge', 'large', 'medium', 'small', ''] as $size) {
            if (($bySize[$size] ?? null) !== null) {
                return $bySize[$size];
            }
        }

        return null;
    }

    private function makeId(string $artist, string $title): string
    {
        return $artist.self::ID_SEPARATOR.$title;
    }

    /** @return array{0: string|null, 1: string|null} */
    private function splitId(string $externalId): array
    {
        $parts = explode(self::ID_SEPARATOR, $externalId, 2);

        return [$this->str($parts[0] ?? null), $this->str($parts[1] ?? null)];
    }
}
