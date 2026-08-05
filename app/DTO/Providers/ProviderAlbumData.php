<?php

declare(strict_types=1);

namespace App\DTO\Providers;

/**
 * An album as every provider describes it, normalized to the fields in
 * 11_PROVIDER_INTEGRATION §5.
 */
final readonly class ProviderAlbumData
{
    /**
     * @param  string  $provider  Adapter key, e.g. `spotify`.
     * @param  string  $externalId  The provider's own ID. Only ever persisted in provider_album_mappings.
     * @param  string|null  $releaseDate  `Y-m-d`; providers that only give a year are widened to 1 January.
     * @param  int|null  $popularity  0–100.
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $title,
        public ?string $artist = null,
        public ?string $genre = null,
        public ?string $language = null,
        public ?string $releaseDate = null,
        public ?string $image = null,
        public ?int $totalTracks = null,
        public ?int $popularity = null,
        public ?string $externalUrl = null,
    ) {}

    /** @see ProviderSongData::checksum() for why these fields and this encoding. */
    public function checksum(): string
    {
        return hash('sha256', serialize([
            $this->title,
            $this->artist,
            $this->genre,
            $this->language,
            $this->releaseDate,
            $this->image,
            $this->totalTracks,
            $this->popularity,
            $this->externalUrl,
        ]));
    }
}
