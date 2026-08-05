<?php

declare(strict_types=1);

namespace App\DTO\Providers;

/**
 * A song as every provider describes it once the provider-specific envelope has
 * been stripped away (11_PROVIDER_INTEGRATION §5).
 *
 * Immutable on purpose: an adapter builds one, the sync engine reads it, and
 * nothing in between may mutate what the provider actually said.
 */
final readonly class ProviderSongData
{
    /**
     * @param  string  $provider  Adapter key, e.g. `spotify`.
     * @param  string  $externalId  The provider's own ID. Only ever persisted in provider_song_mappings.
     * @param  int|null  $duration  Seconds — adapters convert from milliseconds where needed.
     * @param  string|null  $releaseDate  `Y-m-d`; providers that only give a year are widened to 1 January.
     * @param  int|null  $popularity  0–100. Providers using other scales are rescaled by their adapter.
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $title,
        public ?string $artist = null,
        public ?string $album = null,
        public ?int $duration = null,
        public ?string $genre = null,
        public ?string $language = null,
        public ?string $releaseDate = null,
        public ?string $image = null,
        public ?int $popularity = null,
        public ?string $isrc = null,
        public ?string $previewUrl = null,
        public ?string $externalUrl = null,
    ) {}

    /**
     * Fingerprint of everything worth persisting, so an incremental sync can
     * compare against provider_song_mappings.checksum and skip the write when
     * nothing moved.
     *
     * `provider` and `externalId` are excluded: they identify which mapping row
     * the checksum belongs to, so folding them in would add no information.
     * `popularity` IS included — it changes often, but it is a column we serve,
     * so a change in it is a change worth writing.
     *
     * serialize() rather than json_encode(): provider payloads occasionally
     * carry bytes that are not valid UTF-8, which would make json_encode throw
     * on data we are otherwise happy to store.
     */
    public function checksum(): string
    {
        return hash('sha256', serialize([
            $this->title,
            $this->artist,
            $this->album,
            $this->duration,
            $this->genre,
            $this->language,
            $this->releaseDate,
            $this->image,
            $this->popularity,
            $this->isrc,
            $this->previewUrl,
            $this->externalUrl,
        ]));
    }
}
