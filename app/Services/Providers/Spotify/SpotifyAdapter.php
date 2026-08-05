<?php

declare(strict_types=1);

namespace App\Services\Providers\Spotify;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Services\Providers\AbstractProviderAdapter;
use RuntimeException;

/**
 * Spotify Web API via the client-credentials grant.
 *
 * Client credentials give access to the public catalog only — no user data, no
 * playback — which is all the discovery platform needs. The resulting token is
 * cached until just before it expires, so a sync batch performs one token
 * exchange rather than one per record.
 */
final class SpotifyAdapter extends AbstractProviderAdapter
{
    public function key(): string
    {
        return 'spotify';
    }

    protected function hasCredentials(): bool
    {
        return $this->str($this->setting('client_id')) !== null
            && $this->str($this->setting('client_secret')) !== null;
    }

    /**
     * Exchange the client credentials for a bearer token.
     *
     * The token request cannot go through send(): it uses a Basic credential
     * rather than the bearer header the rest of the adapter sends, and a 401
     * here means "bad credentials", not "refresh the token".
     */
    public function authenticate(): void
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException(
                'Spotify is not configured. Set SPOTIFY_ENABLED=true, SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET.'
            );
        }

        if ($this->cachedToken() !== null) {
            return;
        }

        $response = $this->post(
            (string) $this->setting('token_url'),
            ['grant_type' => 'client_credentials'],
            [
                'Authorization' => 'Basic '.base64_encode(
                    $this->setting('client_id').':'.$this->setting('client_secret')
                ),
            ],
        );

        $token = $this->str($this->dig($response ?? [], 'access_token'));

        if ($token === null) {
            // send() has already logged the cause; do not echo the response body,
            // which can contain the credential we just sent.
            return;
        }

        $expiresIn = $this->int($this->dig($response ?? [], 'expires_in')) ?? 3600;
        $leeway = (int) $this->setting('token_leeway_seconds', 60);

        $this->rememberToken($token, max(60, $expiresIn - $leeway));
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        $token = $this->cachedToken();

        return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
    }

    /** @return list<ProviderSongData> */
    public function searchSongs(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search($query, 'track', $limit),
            'tracks.items',
            fn (array $item): ?ProviderSongData => $this->mapSong($item),
        );
    }

    /** @return list<ProviderArtistData> */
    public function searchArtists(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search($query, 'artist', $limit),
            'artists.items',
            fn (array $item): ?ProviderArtistData => $this->mapArtist($item),
        );
    }

    /** @return list<ProviderAlbumData> */
    public function searchAlbums(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search($query, 'album', $limit),
            'albums.items',
            fn (array $item): ?ProviderAlbumData => $this->mapAlbum($item),
        );
    }

    public function getSong(string $externalId): ?ProviderSongData
    {
        $payload = $this->authorizedGet('/tracks/'.rawurlencode($externalId));

        return $payload === null ? null : $this->mapSong($payload);
    }

    public function getArtist(string $externalId): ?ProviderArtistData
    {
        $payload = $this->authorizedGet('/artists/'.rawurlencode($externalId));

        return $payload === null ? null : $this->mapArtist($payload);
    }

    public function getAlbum(string $externalId): ?ProviderAlbumData
    {
        $payload = $this->authorizedGet('/albums/'.rawurlencode($externalId));

        return $payload === null ? null : $this->mapAlbum($payload);
    }

    /** @return array<array-key, mixed>|null */
    private function search(string $query, string $type, int $limit): ?array
    {
        return $this->authorizedGet('/search', [
            'q' => $query,
            'type' => $type,
            // Spotify caps `limit` at 50 and 400s anything larger.
            'limit' => max(1, min(50, $limit)),
            'market' => (string) $this->setting('market', 'US'),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<array-key, mixed>|null
     */
    private function authorizedGet(string $path, array $query = []): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $this->authenticate();

        if ($this->cachedToken() === null) {
            return null;
        }

        return $this->get($this->baseUrl().$path, $query);
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

        $duration = $this->int($this->dig($item, 'duration_ms'));

        return new ProviderSongData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->str($this->dig($item, 'artists.0.name')),
            album: $this->str($this->dig($item, 'album.name')),
            // Spotify reports milliseconds; the catalog stores seconds.
            duration: $duration === null ? null : (int) round($duration / 1000),
            // Tracks carry no genre — only the artist endpoint does.
            genre: null,
            language: null,
            releaseDate: $this->date($this->dig($item, 'album.release_date')),
            image: $this->str($this->dig($item, 'album.images.0.url')),
            popularity: $this->popularity($this->dig($item, 'popularity')),
            isrc: $this->str($this->dig($item, 'external_ids.isrc')),
            previewUrl: $this->str($this->dig($item, 'preview_url')),
            externalUrl: $this->str($this->dig($item, 'external_urls.spotify')),
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
            // Spotify returns a ranked list; the first entry is the primary genre.
            genre: $this->str($this->dig($item, 'genres.0')),
            image: $this->str($this->dig($item, 'images.0.url')),
            bio: null,
            country: null,
            popularity: $this->popularity($this->dig($item, 'popularity')),
            externalUrl: $this->str($this->dig($item, 'external_urls.spotify')),
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
            artist: $this->str($this->dig($item, 'artists.0.name')),
            genre: $this->str($this->dig($item, 'genres.0')),
            language: null,
            releaseDate: $this->date($this->dig($item, 'release_date')),
            image: $this->str($this->dig($item, 'images.0.url')),
            totalTracks: $this->int($this->dig($item, 'total_tracks')),
            popularity: $this->popularity($this->dig($item, 'popularity')),
            externalUrl: $this->str($this->dig($item, 'external_urls.spotify')),
        );
    }
}
