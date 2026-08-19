<?php

declare(strict_types=1);

namespace App\DTO\Providers;

use App\Enums\CreditRole;

/**
 * One artist's credit on one recording, as the provider states it.
 *
 * Carries the name as well as the ID on purpose. {@see ProviderSongData::$artistIds}
 * has existed all along and holds the same IDs, but only the IDs — enough to
 * tell the crawler which artist pages to go and fetch, and not enough to write
 * a credit without a second round trip per person. Since the name is sitting
 * in the very same payload element as the ID, keeping it turns a backfill that
 * would need one request per credited artist into one that needs none.
 *
 * `$name` stays nullable because the ID is the identifying half: a payload that
 * names nobody can still be linked to an artist already mapped from that
 * provider ID.
 */
final readonly class ProviderArtistCredit
{
    /**
     * @param  int  $position  Where the provider listed this person within their role, 0-based.
     *                         Ordering within a role is information: it decides which of two
     *                         singers on a duet a truncated credit line names first.
     */
    public function __construct(
        public string $externalId,
        public CreditRole $role,
        public ?string $name = null,
        public int $position = 0,
    ) {}

    /**
     * Key used to collapse the same person appearing twice.
     *
     * A provider commonly lists one artist under both `artists.primary` and
     * `artists.all` — occasionally with two different roles, which are two
     * genuine credits — so identity is the pair, not the ID alone.
     */
    public function key(): string
    {
        return $this->externalId.'|'.$this->role->value;
    }
}
