<?php

declare(strict_types=1);

namespace App\Services\Providers\Apple;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Services\Providers\AbstractProviderAdapter;
use RuntimeException;

/**
 * Apple Music catalog API.
 *
 * Apple authenticates with a developer token: a JWT signed ES256 with the
 * private key from an Apple Developer MusicKit key (.p8), carrying the team ID
 * as issuer and the key ID in the header.
 *
 * The token is minted here with `openssl_sign` rather than a JWT library. The
 * project has no JWT dependency and adding one for a single 40-line signing
 * routine is not worth the supply-chain surface; the only fiddly part is that
 * OpenSSL emits a DER-encoded ECDSA signature while JWS wants the raw R||S
 * pair, which `derToJose()` converts.
 *
 * Signed tokens are cached for `token_ttl_seconds`, so a sync batch signs once.
 */
final class AppleMusicAdapter extends AbstractProviderAdapter
{
    /** P-256 field size: R and S are each exactly 32 bytes in a JWS ES256 signature. */
    private const COORDINATE_BYTES = 32;

    public function key(): string
    {
        return 'apple';
    }

    protected function hasCredentials(): bool
    {
        return $this->str($this->setting('team_id')) !== null
            && $this->str($this->setting('key_id')) !== null
            && $this->str($this->setting('private_key')) !== null;
    }

    public function authenticate(): void
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException(
                'Apple Music is not configured. Set APPLE_MUSIC_ENABLED=true, APPLE_MUSIC_TEAM_ID, '
                .'APPLE_MUSIC_KEY_ID and APPLE_MUSIC_PRIVATE_KEY (the contents of the .p8 key file).'
            );
        }

        if ($this->cachedToken() !== null) {
            return;
        }

        $ttl = max(60, (int) $this->setting('token_ttl_seconds', 43_200));

        // Expire our copy a minute early so a token cannot lapse mid-request.
        $this->rememberToken($this->mintDeveloperToken($ttl), $ttl - 60);
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
            $this->search($query, 'songs', $limit),
            'results.songs.data',
            fn (array $item): ?ProviderSongData => $this->mapSong($item),
        );
    }

    /** @return list<ProviderArtistData> */
    public function searchArtists(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search($query, 'artists', $limit),
            'results.artists.data',
            fn (array $item): ?ProviderArtistData => $this->mapArtist($item),
        );
    }

    /** @return list<ProviderAlbumData> */
    public function searchAlbums(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search($query, 'albums', $limit),
            'results.albums.data',
            fn (array $item): ?ProviderAlbumData => $this->mapAlbum($item),
        );
    }

    public function getSong(string $externalId): ?ProviderSongData
    {
        $item = $this->firstResource($this->authorizedGet($this->catalog('/songs/'.rawurlencode($externalId))));

        return $item === null ? null : $this->mapSong($item);
    }

    public function getArtist(string $externalId): ?ProviderArtistData
    {
        $item = $this->firstResource($this->authorizedGet($this->catalog('/artists/'.rawurlencode($externalId))));

        return $item === null ? null : $this->mapArtist($item);
    }

    public function getAlbum(string $externalId): ?ProviderAlbumData
    {
        $item = $this->firstResource($this->authorizedGet($this->catalog('/albums/'.rawurlencode($externalId))));

        return $item === null ? null : $this->mapAlbum($item);
    }

    /*
    |--------------------------------------------------------------------------
    | Developer token
    |--------------------------------------------------------------------------
    */

    private function mintDeveloperToken(int $ttl): string
    {
        $privateKey = openssl_pkey_get_private($this->normalizePrivateKey());

        if ($privateKey === false) {
            throw new RuntimeException(
                'Apple Music: APPLE_MUSIC_PRIVATE_KEY could not be read as a PEM private key. '
                .'Paste the whole contents of the .p8 file, including the BEGIN/END lines.'
            );
        }

        $issuedAt = time();

        $header = $this->base64Url((string) json_encode([
            'alg' => 'ES256',
            'kid' => (string) $this->setting('key_id'),
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES));

        $claims = $this->base64Url((string) json_encode([
            'iss' => (string) $this->setting('team_id'),
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttl,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = $header.'.'.$claims;
        $derSignature = '';

        if (! openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Apple Music: failed to sign the developer token with the configured key.');
        }

        return $signingInput.'.'.$this->base64Url($this->derToJose($derSignature));
    }

    /**
     * Accept the key in the forms it realistically arrives in: a proper PEM, a
     * PEM whose newlines survived .env quoting as literal `\n`, or the bare
     * base64 body with the armour stripped.
     */
    private function normalizePrivateKey(): string
    {
        $key = trim((string) $this->setting('private_key'));
        $key = str_replace(['\\n', "\r\n"], "\n", $key);

        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN PRIVATE KEY-----\n"
            .chunk_split(preg_replace('/\s+/', '', $key) ?? '', 64, "\n")
            ."-----END PRIVATE KEY-----\n";
    }

    /**
     * Convert OpenSSL's DER `SEQUENCE { INTEGER r, INTEGER s }` into the fixed
     * 64-byte `R||S` form JWS requires (RFC 7515 §3.4).
     *
     * DER stores each integer minimally and prefixes a 0x00 byte when the high
     * bit would otherwise read as a sign bit, so both values must be trimmed
     * and then left-padded back to 32 bytes.
     */
    private function derToJose(string $der): string
    {
        $offset = 0;

        if (($der[$offset++] ?? '') !== "\x30") {
            throw new RuntimeException('Apple Music: unexpected ECDSA signature encoding (no DER sequence).');
        }

        $sequenceLength = ord($der[$offset++] ?? "\x00");

        // Long-form length: the low bits say how many bytes carry the length.
        if ($sequenceLength >= 0x80) {
            $offset += $sequenceLength - 0x80;
        }

        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            if (($der[$offset++] ?? '') !== "\x02") {
                throw new RuntimeException('Apple Music: unexpected ECDSA signature encoding (no DER integer).');
            }

            $length = ord($der[$offset++] ?? "\x00");
            $value = substr($der, $offset, $length);
            $offset += $length;

            $parts[] = str_pad(ltrim($value, "\x00"), self::COORDINATE_BYTES, "\x00", STR_PAD_LEFT);
        }

        return $parts[0].$parts[1];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /*
    |--------------------------------------------------------------------------
    | Requests and mapping
    |--------------------------------------------------------------------------
    */

    private function catalog(string $path): string
    {
        return $this->baseUrl().'/catalog/'.rawurlencode((string) $this->setting('storefront', 'us')).$path;
    }

    /** @return array<array-key, mixed>|null */
    private function search(string $query, string $type, int $limit): ?array
    {
        return $this->authorizedGet($this->catalog('/search'), [
            'term' => $query,
            'types' => $type,
            // Apple rejects a limit above 25 on the search endpoint.
            'limit' => max(1, min(25, $limit)),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<array-key, mixed>|null
     */
    private function authorizedGet(string $url, array $query = []): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $this->authenticate();

        if ($this->cachedToken() === null) {
            return null;
        }

        return $this->get($url, $query);
    }

    /**
     * Apple wraps even a single-resource lookup in a `data` array.
     *
     * @param  array<array-key, mixed>|null  $payload
     * @return array<array-key, mixed>|null
     */
    private function firstResource(?array $payload): ?array
    {
        $item = $this->dig($payload ?? [], 'data.0');

        return is_array($item) ? $item : null;
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
        $title = $this->str($this->dig($item, 'attributes.name'));

        if ($externalId === null || $title === null) {
            return null;
        }

        $duration = $this->int($this->dig($item, 'attributes.durationInMillis'));

        return new ProviderSongData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->str($this->dig($item, 'attributes.artistName')),
            album: $this->str($this->dig($item, 'attributes.albumName')),
            duration: $duration === null ? null : (int) round($duration / 1000),
            genre: $this->str($this->dig($item, 'attributes.genreNames.0')),
            language: null,
            releaseDate: $this->date($this->dig($item, 'attributes.releaseDate')),
            image: $this->artwork($this->dig($item, 'attributes.artwork')),
            // Apple publishes no popularity score; ranking comes from charts endpoints we do not sync.
            popularity: null,
            isrc: $this->str($this->dig($item, 'attributes.isrc')),
            previewUrl: $this->str($this->dig($item, 'attributes.previews.0.url')),
            externalUrl: $this->str($this->dig($item, 'attributes.url')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapArtist(array $item): ?ProviderArtistData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $name = $this->str($this->dig($item, 'attributes.name'));

        if ($externalId === null || $name === null) {
            return null;
        }

        return new ProviderArtistData(
            provider: $this->key(),
            externalId: $externalId,
            name: $name,
            genre: $this->str($this->dig($item, 'attributes.genreNames.0')),
            image: $this->artwork($this->dig($item, 'attributes.artwork')),
            bio: $this->str($this->dig($item, 'attributes.editorialNotes.standard')),
            country: null,
            popularity: null,
            externalUrl: $this->str($this->dig($item, 'attributes.url')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapAlbum(array $item): ?ProviderAlbumData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $title = $this->str($this->dig($item, 'attributes.name'));

        if ($externalId === null || $title === null) {
            return null;
        }

        return new ProviderAlbumData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->str($this->dig($item, 'attributes.artistName')),
            genre: $this->str($this->dig($item, 'attributes.genreNames.0')),
            language: null,
            releaseDate: $this->date($this->dig($item, 'attributes.releaseDate')),
            image: $this->artwork($this->dig($item, 'attributes.artwork')),
            totalTracks: $this->int($this->dig($item, 'attributes.trackCount')),
            popularity: null,
            externalUrl: $this->str($this->dig($item, 'attributes.url')),
        );
    }

    /**
     * Apple hands back an artwork template with `{w}` and `{h}` placeholders
     * rather than a URL. Resolve it once here so nothing downstream has to know
     * that Apple artwork is special.
     */
    private function artwork(mixed $artwork): ?string
    {
        if (! is_array($artwork)) {
            return null;
        }

        $url = $this->str($artwork['url'] ?? null);

        if ($url === null) {
            return null;
        }

        return str_replace(['{w}', '{h}', '{f}'], ['640', '640', 'jpg'], $url);
    }
}
