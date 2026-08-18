<?php

declare(strict_types=1);

namespace App\DTO\Providers;

/**
 * A provider-curated playlist, stripped of its provider envelope
 * (11_PROVIDER_INTEGRATION §5).
 *
 * Unlike the song/artist/album DTOs, this one is not part of
 * {@see \App\Contracts\Providers\ProviderAdapter}. Most providers in this
 * codebase expose no playlist concept at all, and the ones that do disagree
 * about what a playlist even is (Spotify's are user-owned, JioSaavn's are
 * editorial). Adapters that can serve them advertise it by implementing
 * {@see \App\Contracts\Providers\SupportsPlaylists} instead.
 *
 * `$songs` carries the tracklist inline because that is how JioSaavn hands it
 * over — `/playlists?id=` returns the playlist's metadata and a page of its
 * full song records in one response, so splitting them into two DTOs would
 * mean throwing away data we have already paid a request for.
 */
final readonly class ProviderPlaylistData
{
    /**
     * @param  string  $provider  Adapter key, e.g. `jiosaavn`.
     * @param  string  $externalId  The provider's own ID. Only ever persisted in provider_playlist_mappings.
     * @param  int|null  $songCount  The provider's own total, which may exceed count($songs) when paging.
     * @param  list<ProviderSongData>  $songs  This page of the tracklist, in playlist order.
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $title,
        public ?string $description = null,
        public ?string $image = null,
        public ?string $language = null,
        public ?int $songCount = null,
        public ?int $followerCount = null,
        public ?string $externalUrl = null,
        public array $songs = [],
    ) {}

    /**
     * Fingerprint used to skip a rewrite when nothing changed, matching the
     * other provider DTOs.
     *
     * The tracklist is folded in as a list of external IDs rather than the
     * full song payloads: a playlist's identity for sync purposes is "which
     * tracks, in what order", and each song's own metadata is checksummed
     * separately by its own mapping row. Including the payloads would make
     * every playlist look changed whenever any one track's play count moved,
     * which for an editorial playlist is more or less always.
     *
     * `songCount` is excluded for the same reason it cannot be trusted as a
     * completeness signal: the provider reports the playlist total on every
     * page, so a paged fetch would otherwise produce a different checksum per
     * page for the same unchanged playlist.
     */
    public function checksum(): string
    {
        return hash('sha256', serialize([
            $this->title,
            $this->description,
            $this->image,
            $this->language,
            $this->externalUrl,
            array_map(static fn (ProviderSongData $song): string => $song->externalId, $this->songs),
        ]));
    }
}
