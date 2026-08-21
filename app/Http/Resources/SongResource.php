<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Song;
use App\Models\SongCredit;
use App\Models\User;
use App\Services\Catalog\AudioAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Song
 */
final class SongResource extends JsonResource
{
    /**
     * Raw foreign keys (artist_id, album_id, genre_id, language_id) are never
     * emitted — clients navigate through the nested objects. Provider mappings
     * are never emitted at all: exposing a provider's own id would leak the
     * upstream schema and breach its terms (CONVENTIONS "Non-negotiables").
     *
     * Every relationship is read through `whenLoaded`, so a caller that forgot
     * to eager-load gets a smaller payload rather than a lazy-loading violation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            // Null for singles and for providers that omit it.
            'track_number' => $this->track_number,
            'duration' => $this->duration,
            'isrc' => $this->isrc,
            'release_date' => $this->release_date?->toDateString(),
            'popularity' => $this->popularity,
            /*
             | Clamped to the caller's plan, not emitted raw. The stored column
             | is the top of the provider's ladder, so returning it here handed
             | every free account a premium-quality URL and made the ceiling in
             | PlaybackService unenforceable — see AudioAccess for the whole
             | account. Guests resolve to the free tier rather than to null:
             | the catalog stays audible without an account.
             */
            'preview_url' => $this->audioUrlFor($request),
            'external_url' => $this->external_url,

            /*
             | Only present when this song was serialised off `Playlist::songs()`
             | — the one relation that `->using(PlaylistTrack::class)`, so
             | `$this->pivot` is a real `PlaylistTrack` there and null everywhere
             | else (search, an album's tracklist, `GET /songs/{id}`).
             */
            'added_at' => $this->when(
                $this->pivot !== null,
                fn (): ?string => $this->pivot->added_at?->toIso8601String(),
            ),

            /*
             | The closure form of whenLoaded is required rather than
             | `ArtistResource::make($this->whenLoaded('artist'))`: it yields
             | null for a loaded-but-null relation (a song with no album) and
             | MissingValue only when the relation was never loaded.
             */
            'artist' => $this->whenLoaded(
                'artist',
                fn (): ArtistResource => ArtistResource::make($this->artist),
            ),
            'album' => $this->whenLoaded(
                'album',
                fn (): AlbumResource => AlbumResource::make($this->album),
            ),

            // Genre and language are flattened here; the song payload only ever
            // needs a label, and the full resources belong to their own lists.
            'genre' => $this->whenLoaded('genre', fn (): array => [
                'id' => $this->genre->id,
                'name' => $this->genre->name,
            ]),
            'language' => $this->whenLoaded('language', fn (): array => [
                'id' => $this->language->id,
                'name' => $this->language->name,
            ]),

            /*
             | Everyone the provider credits, with the role. `whenLoaded` like
             | every other relation here, so this costs nothing on the listing
             | endpoints that do not ask for it — a tracklist serialising fifty
             | songs must not turn into fifty credit queries.
             |
             | `role` is the normalized CreditRole value and `role_label` is how
             | it reads in a credits block. Both, because a client needs to
             | switch on the first and display the second, and deriving the
             | label client-side would mean re-implementing the vocabulary in
             | TypeScript and letting the two drift.
             |
             | Actor credits ARE included here, unlike in the discography query:
             | a soundtrack's credits block is exactly where "starring" belongs.
             | What it must not do is put someone else's songs on an actor's
             | page — see CreditRole::isMusicCredit().
             */
            'credits' => $this->whenLoaded('credits', fn (): array => $this->credits
                ->filter(fn (SongCredit $credit): bool => $credit->relationLoaded('artist') && $credit->artist !== null)
                ->map(fn (SongCredit $credit): array => [
                    'role' => $credit->role->value,
                    'role_label' => $credit->role->label(),
                    'artist' => [
                        'id' => $credit->artist->id,
                        'name' => $credit->artist->name,
                    ],
                ])
                ->values()
                ->all()),
        ];
    }

    /**
     * The playable URL for whoever is asking.
     *
     * Resolved through the container rather than injected: a JsonResource is
     * constructed by Eloquent collections and by `::make()` all over the app,
     * so it has no constructor to inject into. `AudioAccess` is request-scoped
     * and memoises the plan lookup, so serialising a fifty-row tracklist still
     * reads the subscription once.
     */
    private function audioUrlFor(Request $request): ?string
    {
        $user = $request->user();

        return app(AudioAccess::class)->urlFor($this->resource, $user instanceof User ? $user : null);
    }
}
