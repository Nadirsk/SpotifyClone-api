<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AudioQuality;
use App\Enums\SubscriptionPlan;
use App\Models\Song;
use App\Models\User;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\SubscriptionService;

/**
 * The audio URL a given listener is allowed to be handed, for any song in any
 * payload.
 *
 * ## Why this exists
 *
 * `PlaybackService` already clamps quality to the caller's plan, but it only
 * guards `GET /songs/{id}/stream` and `GET /songs/{id}/download`. Every *listing*
 * — album tracks, search results, a playlist, the trending shelf — went through
 * `SongResource`, which emitted `songs.preview_url` verbatim. That column holds
 * the top of the provider's ladder (`…_320.mp4`), so a free account received a
 * 320kbps URL in the album payload and simply played it. The plan ceiling was
 * real in one endpoint and decorative everywhere else, which is the same thing
 * as not having one: no client-side check can fix a URL the server volunteered.
 *
 * So the clamp moves to the boundary every song payload crosses. A free listener
 * is now *unable* to obtain a premium-quality URL from this API, whatever they
 * call, because no response contains one.
 *
 * ## Why the ceiling is cached per listener rather than once
 *
 * Resolving a ceiling reads the caller's subscription, and a fifty-row tracklist
 * serialises fifty songs through `SongResource` — so without memoisation one
 * listing becomes fifty subscription lookups.
 *
 * The obvious memoisation is a single slot, on the reasoning that a request has
 * only one caller. That is a bug waiting for a long-lived container: this is
 * bound `scoped`, but scoped instances are not flushed between requests in every
 * runtime (a test process and an Octane worker both reuse them), and a stale slot
 * here means one listener's entitlement served to the next. That failure was
 * reproduced — a guest warmed an album payload and the Premium account behind
 * them was served 96kbps.
 *
 * Keying by user id removes the dependency on container lifetime altogether: a
 * stale entry can only ever be returned to the same account it was computed for.
 * The map is bounded by the number of distinct listeners the instance sees, which
 * for a per-request instance is one.
 */
final class AudioAccess
{
    /**
     * Ceiling by user id, with the empty string standing for "no account".
     *
     * @var array<string, AudioQuality>
     */
    private array $ceilings = [];

    public function __construct(
        private readonly AudioSourceResolver $resolver,
        private readonly SubscriptionService $subscriptions,
        private readonly PlanCatalog $plans,
    ) {}

    /**
     * The URL to hand this listener for this song, or null when the song has no
     * audio at all.
     *
     * Degrades rather than refuses: a song whose stored URL is not in the
     * provider's recognised variant shape comes back untouched, which is the
     * behaviour the catalog had before quality tiers existed. That is not a hole
     * — the ladder is derived from the URL's own bitrate suffix, so a URL without
     * one has no premium variant to withhold.
     */
    public function urlFor(Song $song, ?User $user): ?string
    {
        $resolved = $this->resolver->resolve($song, $this->ceilingFor($user));

        return $resolved['url'] ?? null;
    }

    /**
     * The best tier this listener may reach. Guests get the free tier's ceiling —
     * the honest answer for someone with no account, and what keeps the catalog
     * audible without one.
     */
    public function ceilingFor(?User $user): AudioQuality
    {
        $key = $user === null ? '' : (string) $user->getKey();

        return $this->ceilings[$key] ??= $user === null
            ? $this->plans->maxQuality(SubscriptionPlan::Free)
            : AudioQuality::from($this->subscriptions->entitlementsFor($user)['max_audio_quality']);
    }
}
