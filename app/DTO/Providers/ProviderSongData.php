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
     * @param  string|null  $albumId  The provider's own album ID, where the song payload names it.
     *                                Never persisted on the song row — it exists so the crawler can queue
     *                                the album as a target instead of having to find it again by title.
     * @param  list<string>  $artistIds  Provider IDs of every artist credited on the recording, primary and
     *                                   featured. Also never persisted: `$artist` is the name the row is
     *                                   written from, and these are what make the discovery closure
     *                                   recursive — a song reached from anywhere hands the crawler every
     *                                   artist behind it.
     * @param  list<ProviderArtistCredit>  $credits  Every artist the provider credits, with the role it credits
     *                                               them in. Unlike `$artistIds` — which is the same set of
     *                                               people reduced to bare IDs for the crawler to walk — these
     *                                               ARE persisted, into `song_credits`. They are what lets a
     *                                               composer's or a featured vocalist's page list the work they
     *                                               are actually on, which one `artist_id` column cannot express.
     * @param  int|null  $playCount  The provider's raw counter, unscaled. Distinct from `$popularity`, which
     *                               is that same number squashed into the schema's 0–100 column.
     * @param  int|null  $trackNumber  Position on its album, 1-based. Null from every search result, and that
     *                                 is not a gap to be filled in later: a song search returns songs, with no
     *                                 album context in which a position would mean anything. Only a
     *                                 tracklist fetch can know it.
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
        public ?string $albumId = null,
        public array $artistIds = [],
        public array $credits = [],
        public ?int $playCount = null,
        public ?string $label = null,
        public ?string $copyright = null,
        public ?bool $explicit = null,
        public ?bool $hasLyrics = null,
        public ?int $trackNumber = null,
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
     *
     * Three of the newer fields are deliberately left out:
     *
     * - `albumId` and `artistIds` are routing information for the crawler, not
     *   columns. Nothing about the stored song changes when they do.
     * - `playCount` is a live counter that ticks upward on a popular track by
     *   the minute. Folding it in would make every song in the catalog look
     *   changed on every refresh, which would turn off the one thing keeping an
     *   hourly sync to a few hundred writes instead of tens of thousands.
     *   `popularity` is already here and is derived from the same number, so a
     *   play count that moves *meaningfully* still rewrites the row; a handful
     *   of extra plays no longer does.
     *
     * `trackNumber` IS folded in, and has to be. The short-circuit above
     * returns before `writeEntity()` ever runs, so a song first seen in a
     * *search* — where the position is unknowable — would match on checksum
     * when its album's tracklist arrived afterwards, and the position would be
     * discarded unwritten. That is exactly how the whole column ended up null.
     *
     * `credits` IS folded in, for the same reason `trackNumber` is: the
     * short-circuit above returns before `writeEntity()` runs, so a provider
     * that corrects a credit — or a payload shape that starts carrying one it
     * did not before — would match on checksum and the correction would never
     * be written. It is safe to include only because the adapter emits credits
     * in a deterministic order; an unsorted list would rewrite every song on
     * every refresh as the provider reshuffled its own array.
     *
     * The cost is that a song reachable both ways alternates between two
     * checksums and is rewritten each time the other path runs. That is one
     * UPDATE per dual-sourced song per full bootstrap, it never loses data
     * (`writeEntity()` drops the null, so the search path cannot clear a
     * position the tracklist set), and it is the price of the column being
     * populated at all.
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
            $this->label,
            $this->copyright,
            $this->explicit,
            $this->hasLyrics,
            $this->trackNumber,
            array_map(static fn (ProviderArtistCredit $credit): string => $credit->key(), $this->credits),
        ]));
    }
}
