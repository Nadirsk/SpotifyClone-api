<?php

declare(strict_types=1);

namespace App\DTO\Providers;

/**
 * An artist as every provider describes it, normalized to the fields in
 * 11_PROVIDER_INTEGRATION §5.
 */
final readonly class ProviderArtistData
{
    /**
     * @param  string  $provider  Adapter key, e.g. `spotify`.
     * @param  string  $externalId  The provider's own ID. Only ever persisted in provider_artist_mappings.
     * @param  string|null  $country  ISO 3166-1 alpha-2; the column is two characters wide.
     * @param  int|null  $popularity  0–100.
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $name,
        public ?string $genre = null,
        public ?string $image = null,
        public ?string $bio = null,
        public ?string $country = null,
        public ?int $popularity = null,
        public ?string $externalUrl = null,
    ) {}

    /** @see ProviderSongData::checksum() for why these fields and this encoding. */
    public function checksum(): string
    {
        return hash('sha256', serialize([
            $this->name,
            $this->genre,
            $this->image,
            $this->bio,
            $this->country,
            $this->popularity,
            $this->externalUrl,
        ]));
    }
}
