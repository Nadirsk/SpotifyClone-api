<?php

declare(strict_types=1);

namespace App\Services\Providers\ITunes;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Services\Providers\AbstractProviderAdapter;

/**
 * Apple's free public Search API (`itunes.apple.com`) — no key, no OAuth.
 *
 * Distinct from `App\Services\Providers\Apple\AppleMusicAdapter`, which is the
 * paid catalog API behind a signed developer JWT this project has no
 * credentials for. This one needs only the enabled flag.
 *
 * Two things this API genuinely cannot supply, unlike JioSaavn:
 *
 * - no popularity score at all (ranking lives behind charts endpoints this
 *   adapter does not call), so `popularity` is always null;
 * - only a 30-second preview clip — there is no full-length field to map the
 *   way JioSaavnAdapter maps `downloadUrl`. That is a real ceiling of this
 *   provider, not an oversight.
 *
 * What it gives that JioSaavn's search response does not: a genre per result
 * (`primaryGenreName`) and, for `getAlbum`, a real total track count from the
 * lookup response.
 */
final class ITunesSearchAdapter extends AbstractProviderAdapter
{
    public function key(): string
    {
        return 'itunes';
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
            $this->search($query, 'song', $limit),
            fn (array $item): ?ProviderSongData => $this->mapSong($item),
        );
    }

    /** @return list<ProviderArtistData> */
    public function searchArtists(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search($query, 'musicArtist', $limit),
            fn (array $item): ?ProviderArtistData => $this->mapArtist($item),
        );
    }

    /** @return list<ProviderAlbumData> */
    public function searchAlbums(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search($query, 'album', $limit),
            fn (array $item): ?ProviderAlbumData => $this->mapAlbum($item),
        );
    }

    public function getSong(string $externalId): ?ProviderSongData
    {
        $item = $this->firstOfKind($this->lookup($externalId), fn (array $item): bool => $this->str($this->dig($item, 'wrapperType')) === 'track');

        return $item === null ? null : $this->mapSong($item);
    }

    public function getArtist(string $externalId): ?ProviderArtistData
    {
        $item = $this->firstOfKind($this->lookup($externalId), fn (array $item): bool => $this->str($this->dig($item, 'wrapperType')) === 'artist');

        return $item === null ? null : $this->mapArtist($item);
    }

    public function getAlbum(string $externalId): ?ProviderAlbumData
    {
        /*
         | `entity=song` is required to get Apple to return the collection at
         | all when looking up by a bare id; a plain `/lookup?id=` sometimes
         | answers with nothing for a collection id. limit=200 (Apple's own
         | ceiling) is a single request that also happens to be exactly what
         | the frontend's own `itunes.ts` adapter already relies on for the
         | same lookup shape.
         */
        $items = $this->lookup($externalId, ['entity' => 'song', 'limit' => 200]);
        $item = $this->firstOfKind($items, fn (array $item): bool => $this->str($this->dig($item, 'wrapperType')) === 'collection');

        if ($item === null) {
            return null;
        }

        $album = $this->mapAlbum($item);

        if ($album === null) {
            return null;
        }

        // The collection entity itself carries no track count; count what
        // the same lookup actually returned instead of trusting a stale field.
        $trackCount = count(array_filter(
            $items ?? [],
            fn (mixed $entity): bool => is_array($entity) && $this->str($this->dig($entity, 'wrapperType')) === 'track',
        ));

        return $trackCount > 0
            ? new ProviderAlbumData(
                provider: $album->provider,
                externalId: $album->externalId,
                title: $album->title,
                artist: $album->artist,
                genre: $album->genre,
                language: $album->language,
                releaseDate: $album->releaseDate,
                image: $album->image,
                totalTracks: $trackCount,
                popularity: $album->popularity,
                externalUrl: $album->externalUrl,
            )
            : $album;
    }

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    */

    /** @return array<array-key, mixed>|null */
    private function search(string $query, string $entity, int $limit): ?array
    {
        return $this->fetch('/search', [
            'term' => $query,
            'media' => 'music',
            'entity' => $entity,
            // Apple's own documented ceiling for the search endpoint.
            'limit' => max(1, min(200, $limit)),
            'country' => $this->country(),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $extra
     * @return list<array<array-key, mixed>>|null
     */
    private function lookup(string $id, array $extra = []): ?array
    {
        $payload = $this->fetch('/lookup', [...$extra, 'id' => $id, 'country' => $this->country()]);
        $results = $payload === null ? null : $this->dig($payload, 'results');

        return is_array($results) ? $results : null;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<array-key, mixed>|null
     */
    private function fetch(string $path, array $query): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return $this->get($this->baseUrl().$path, $query);
    }

    private function country(): string
    {
        return (string) $this->setting('country', 'us');
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
        $items = $this->dig($payload ?? [], 'results', []);

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

    /**
     * @param  list<array<array-key, mixed>>|null  $items
     * @param  callable(array<array-key, mixed>): bool  $matches
     * @return array<array-key, mixed>|null
     */
    private function firstOfKind(?array $items, callable $matches): ?array
    {
        foreach ($items ?? [] as $item) {
            if (is_array($item) && $matches($item)) {
                return $item;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Mapping
    |--------------------------------------------------------------------------
    */

    /** @param array<array-key, mixed> $item */
    private function mapSong(array $item): ?ProviderSongData
    {
        $externalId = $this->str($this->dig($item, 'trackId'));
        $title = $this->str($this->dig($item, 'trackName'));

        if ($externalId === null || $title === null) {
            return null;
        }

        $durationMs = $this->int($this->dig($item, 'trackTimeMillis'));

        return new ProviderSongData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->primaryArtist($this->str($this->dig($item, 'artistName'))),
            album: $this->str($this->dig($item, 'collectionName')),
            duration: $durationMs === null ? null : (int) round($durationMs / 1000),
            genre: $this->str($this->dig($item, 'primaryGenreName')),
            language: null,
            releaseDate: $this->date($this->dig($item, 'releaseDate')),
            image: $this->artwork($this->dig($item, 'artworkUrl100')),
            // Apple's Search API publishes no popularity score at all.
            popularity: null,
            isrc: null,
            previewUrl: $this->str($this->dig($item, 'previewUrl')),
            externalUrl: $this->str($this->dig($item, 'trackViewUrl')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapArtist(array $item): ?ProviderArtistData
    {
        $externalId = $this->str($this->dig($item, 'artistId'));
        $name = $this->str($this->dig($item, 'artistName'));

        if ($externalId === null || $name === null) {
            return null;
        }

        return new ProviderArtistData(
            provider: $this->key(),
            externalId: $externalId,
            name: $name,
            genre: $this->str($this->dig($item, 'primaryGenreName')),
            // The search/lookup endpoints carry no artist artwork at all.
            image: null,
            bio: null,
            country: null,
            popularity: null,
            externalUrl: $this->str($this->dig($item, 'artistLinkUrl')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapAlbum(array $item): ?ProviderAlbumData
    {
        $externalId = $this->str($this->dig($item, 'collectionId'));
        $title = $this->str($this->dig($item, 'collectionName'));

        if ($externalId === null || $title === null) {
            return null;
        }

        return new ProviderAlbumData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->primaryArtist($this->str($this->dig($item, 'artistName'))),
            genre: $this->str($this->dig($item, 'primaryGenreName')),
            language: null,
            releaseDate: $this->date($this->dig($item, 'releaseDate')),
            image: $this->artwork($this->dig($item, 'artworkUrl100')),
            totalTracks: $this->int($this->dig($item, 'trackCount')),
            popularity: null,
            externalUrl: $this->str($this->dig($item, 'collectionViewUrl')),
        );
    }

    /**
     * Apple bakes every collaborator into one string
     * (`"Pritam, Arijit Singh"`, `"Atif Aslam & Shreya Ghoshal"`). The DTOs
     * here only take a single artist name, and `MetadataNormalizer::resolveArtist()`
     * would otherwise `firstOrCreate` a garbage artist literally named the
     * whole credit line — one that can never dedupe against the same artist
     * synced cleanly from JioSaavn. Taking the first name is what the
     * frontend's own `itunes.ts` adapter already does for the same reason.
     */
    private function primaryArtist(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $parts = preg_split('/\s*(?:,|&|\bfeat\.|\bft\.|\bwith\b)\s*/i', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false || $parts === [] ? $raw : $this->str($parts[0]);
    }

    /**
     * Apple only ever returns the 100x100 thumbnail; swapping the dimensions
     * in the path is the documented way to get a larger render off the same
     * CDN — there is no separate hi-res field to ask for.
     */
    private function artwork(mixed $url): ?string
    {
        $url = $this->str($url);

        if ($url === null) {
            return null;
        }

        return preg_replace('/\/\d+x\d+bb\./', '/600x600bb.', $url) ?? $url;
    }
}
