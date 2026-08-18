<?php

declare(strict_types=1);

namespace App\DTO\Providers;

/**
 * One page of a paged provider listing, carrying the provider's own total
 * alongside the items.
 *
 * The total is the point. Every other DTO here describes a record; this one
 * exists because a crawler cannot tell "that was the last page" from "this
 * page came back short" without it, and JioSaavn does both routinely — it
 * caps artist listings at 10 per page regardless of the requested limit, and
 * a search asked for 50 answers with 40 while hundreds more genuinely remain.
 * Paging against the total is the only way to walk a listing to its end
 * without either truncating it or crawling forever.
 *
 * @template TItem of ProviderSongData|ProviderAlbumData|ProviderArtistData
 */
final readonly class ProviderPage
{
    /**
     * @param  list<TItem>  $items
     * @param  int|null  $total  The provider's count of *all* matching records, not this page's.
     *                           Null when the provider did not say, which callers must treat as
     *                           "keep going until a page comes back empty".
     * @param  int  $page  Zero-based index of the page these items came from.
     */
    public function __construct(
        public array $items,
        public ?int $total = null,
        public int $page = 0,
    ) {}

    /**
     * Whether a page after this one is worth requesting.
     *
     * An empty page always ends the walk — with or without a total, there is
     * nothing left to page into. Otherwise the decision rests on the total:
     * $seen is how many items the caller has accumulated across every page so
     * far, and the walk continues until that reaches it.
     *
     * With no total the answer is an optimistic true, ending the walk one
     * wasted request later on an empty page. That is the right trade: the
     * alternative — inferring the end from a short page — silently drops real
     * records on this provider.
     */
    public function hasMore(int $seen): bool
    {
        if ($this->items === []) {
            return false;
        }

        return $this->total === null || $seen < $this->total;
    }

    /** @return static<TItem> */
    public static function empty(int $page = 0): self
    {
        return new self(items: [], total: null, page: $page);
    }
}
