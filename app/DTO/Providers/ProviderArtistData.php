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
     * @param  int|null  $followerCount  The provider's raw follower number, unscaled. `$popularity` is
     *                                   this rescaled to the schema's 0–100 column, so the two move
     *                                   together but only this one is comparable across artists.
     * @param  string|null  $dominantType  What the provider mostly files this artist under
     *                                     (`singer`, `music director`, `actor`, …).
     * @param  string|null  $birthDate  As the provider states it; formats vary, so it is stored verbatim
     *                                  rather than coerced into a date column that would reject half of them.
     * @param  list<string>  $availableLanguages  Languages the artist has a catalog in.
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
        public ?int $followerCount = null,
        public ?bool $isVerified = null,
        public ?string $dominantLanguage = null,
        public ?string $dominantType = null,
        public ?string $birthDate = null,
        public ?string $facebookUrl = null,
        public ?string $twitterUrl = null,
        public ?string $wikiUrl = null,
        public array $availableLanguages = [],
    ) {}

    /**
     * @see ProviderSongData::checksum() for why these fields and this encoding.
     *
     * `followerCount` is excluded for the same reason a song's raw play count
     * is: it moves continuously on a popular artist, and including it would
     * mean rewriting the artist row on every single refresh. The rescaled
     * `popularity` is in, so a follower count that moves enough to matter
     * still writes.
     */
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
            $this->isVerified,
            $this->dominantLanguage,
            $this->dominantType,
            $this->birthDate,
            $this->facebookUrl,
            $this->twitterUrl,
            $this->wikiUrl,
            $this->availableLanguages,
        ]));
    }
}
