<?php

declare(strict_types=1);

namespace App\Services\Providers\MusicBrainz;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Services\Providers\AbstractProviderAdapter;
use RuntimeException;

/**
 * MusicBrainz web service.
 *
 * Open data, no API key — but two rules are enforced rather than advisory:
 *
 * 1. every request must carry a User-Agent naming the application, its version
 *    and a contact address, or MusicBrainz blocks the client. The adapter
 *    therefore treats `MUSICBRAINZ_USER_AGENT` as a credential and reports
 *    itself unconfigured without it, instead of getting the deployment banned.
 * 2. one request per second, averaged. That is expressed as
 *    `rate_limit: {requests: 1, per_seconds: 1}` in config/providers.php and
 *    enforced by the throttle in AbstractProviderAdapter, which is why the
 *    MusicBrainz sync is materially slower than the others by design.
 *
 * MusicBrainz has no notion of popularity and no audio, so those fields stay
 * null; its value is ISRCs, canonical titles and release languages, which make
 * it a strong dedupe and enrichment source rather than a discovery source.
 */
final class MusicBrainzAdapter extends AbstractProviderAdapter
{
    public function key(): string
    {
        return 'musicbrainz';
    }

    protected function hasCredentials(): bool
    {
        return $this->str($this->setting('user_agent')) !== null;
    }

    public function authenticate(): void
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException(
                'MusicBrainz is not configured. Set MUSICBRAINZ_ENABLED=true and MUSICBRAINZ_USER_AGENT to a '
                .'descriptive value such as "MusicDiscovery/1.0 ( contact@example.com )" — MusicBrainz blocks '
                .'clients that do not identify themselves.'
            );
        }
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['User-Agent' => (string) $this->setting('user_agent')];
    }

    /** @return list<ProviderSongData> */
    public function searchSongs(string $query, int $limit): array
    {
        $payload = $this->fetch('/recording', [
            'query' => $query,
            'limit' => max(1, min(100, $limit)),
        ]);

        return $this->mapAll($payload, 'recordings', fn (array $item): ?ProviderSongData => $this->mapSong($item));
    }

    /** @return list<ProviderArtistData> */
    public function searchArtists(string $query, int $limit): array
    {
        $payload = $this->fetch('/artist', [
            'query' => $query,
            'limit' => max(1, min(100, $limit)),
        ]);

        return $this->mapAll($payload, 'artists', fn (array $item): ?ProviderArtistData => $this->mapArtist($item));
    }

    /** @return list<ProviderAlbumData> */
    public function searchAlbums(string $query, int $limit): array
    {
        // Release groups, not releases: one entry per album rather than one per
        // pressing, which is what the catalog models.
        $payload = $this->fetch('/release-group', [
            'query' => $query,
            'limit' => max(1, min(100, $limit)),
        ]);

        return $this->mapAll($payload, 'release-groups', fn (array $item): ?ProviderAlbumData => $this->mapAlbum($item));
    }

    public function getSong(string $externalId): ?ProviderSongData
    {
        $payload = $this->fetch('/recording/'.rawurlencode($externalId), [
            // `isrcs` is the reason MusicBrainz is worth syncing at all — it is
            // the highest-priority dedupe key (07_SYNC_ENGINE §6).
            'inc' => 'artist-credits+releases+isrcs+tags',
        ]);

        return $payload === null ? null : $this->mapSong($payload);
    }

    public function getArtist(string $externalId): ?ProviderArtistData
    {
        $payload = $this->fetch('/artist/'.rawurlencode($externalId), ['inc' => 'tags']);

        return $payload === null ? null : $this->mapArtist($payload);
    }

    public function getAlbum(string $externalId): ?ProviderAlbumData
    {
        $payload = $this->fetch('/release-group/'.rawurlencode($externalId), [
            'inc' => 'artist-credits+releases+tags',
        ]);

        return $payload === null ? null : $this->mapAlbum($payload);
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

        // The web service defaults to XML; every endpoint needs fmt=json.
        return $this->get($this->baseUrl().$path, array_merge($query, ['fmt' => 'json']));
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
        $title = $this->str($this->dig($item, 'title'));

        if ($externalId === null || $title === null) {
            return null;
        }

        $length = $this->int($this->dig($item, 'length'));

        return new ProviderSongData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->str($this->dig($item, 'artist-credit.0.name'))
                ?? $this->str($this->dig($item, 'artist-credit.0.artist.name')),
            album: $this->str($this->dig($item, 'releases.0.title')),
            // `length` is milliseconds.
            duration: $length === null ? null : (int) round($length / 1000),
            // Folksonomy tags stand in for a genre; the first is the most used.
            genre: $this->str($this->dig($item, 'tags.0.name')),
            // ISO 639-3 on the release; MetadataNormalizer maps it to 639-1.
            language: $this->str($this->dig($item, 'releases.0.text-representation.language')),
            releaseDate: $this->date($this->dig($item, 'first-release-date'))
                ?? $this->date($this->dig($item, 'releases.0.date')),
            // MusicBrainz stores no artwork itself; covers live at the Cover Art Archive.
            image: null,
            popularity: null,
            isrc: $this->str($this->dig($item, 'isrcs.0')),
            previewUrl: null,
            externalUrl: 'https://musicbrainz.org/recording/'.$externalId,
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

        $country = $this->str($this->dig($item, 'country'));

        return new ProviderArtistData(
            provider: $this->key(),
            externalId: $externalId,
            name: $name,
            genre: $this->str($this->dig($item, 'tags.0.name')),
            image: null,
            bio: $this->str($this->dig($item, 'disambiguation')),
            // The column is CHAR(2); MusicBrainz occasionally returns "XW" style
            // pseudo-codes of other lengths, which are dropped rather than truncated.
            country: $country !== null && strlen($country) === 2 ? strtoupper($country) : null,
            popularity: null,
            externalUrl: 'https://musicbrainz.org/artist/'.$externalId,
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
            artist: $this->str($this->dig($item, 'artist-credit.0.name'))
                ?? $this->str($this->dig($item, 'artist-credit.0.artist.name')),
            genre: $this->str($this->dig($item, 'tags.0.name')),
            language: $this->str($this->dig($item, 'releases.0.text-representation.language')),
            releaseDate: $this->date($this->dig($item, 'first-release-date')),
            image: null,
            totalTracks: null,
            popularity: null,
            externalUrl: 'https://musicbrainz.org/release-group/'.$externalId,
        );
    }
}
