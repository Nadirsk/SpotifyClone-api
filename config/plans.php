<?php

declare(strict_types=1);

use App\Enums\AudioQuality;
use App\Enums\SubscriptionPlan;

/*
|--------------------------------------------------------------------------
| Subscription plans
|--------------------------------------------------------------------------
|
| The single source of truth for what each tier costs and what it unlocks.
| `PlanCatalog` reads this and nothing else, so a price change is a config
| edit rather than a code change.
|
| ## On pricing
|
| The commercial rule is "20% below Spotify", so the reference price is what
| is stored and our price is *derived* from it at read time
| (`PlanCatalog::priceFor()`). Storing both would let the two drift and make
| the discount unverifiable; storing only ours would lose the comparison the
| plans page is required to show.
|
| Reference figures are Spotify's list prices in the two launch markets, in
| minor units (paise / cents) so no float ever touches money:
|
|   Standard  IN ₹139.00   US $11.99
|   Platinum  IN ₹299.00   US $19.99
|   Student   IN  ₹69.00   US  $5.99
|
| These need re-checking whenever Spotify repositions — nothing here can
| detect that on its own. `discount_rate` is the knob if the 20% ever moves.
|
| ## On entitlements
|
| Every gate in the app resolves through `entitlements` below rather than
| testing the plan name, so adding a tier never means hunting for
| `=== 'platinum'` comparisons. Keys are stable contract with the frontend —
| `GET /plans` ships them verbatim to build the comparison table.
|
| ## On `accounts` versus `max_sessions`
|
| These are two different numbers and conflating them is the bug this pair of
| keys exists to prevent.
|
| `accounts` is **seats** — how many people a plan covers, the Duo/Family
| concept in `12_SCOPE_OF_WORK` FR.I.1(a). Nothing enforces it, because no
| invite or seat-management mechanism exists yet; it is display copy on the
| plan card and nothing more. Do not gate anything on it.
|
| `max_sessions` is **concurrent logged-in devices**, and it *is* enforced —
| by `DeviceSessionService`, at the moment a token is minted. `null` means
| uncapped.
|
| ### What this cannot do
|
| It caps **logins, not playback.** `12_SCOPE_OF_WORK` §FR.I "The one hard
| blocker" spells out why: a concurrent-*stream* limit can only be counted by
| whoever serves the audio, and this platform serves none — it holds provider
| deep-links (`11` §2) and audio hosting is out of scope (`01` §12). So two
| devices that are both inside the cap can still play at the same time, and
| nothing here can see that, let alone stop it. Anyone reading these numbers
| as "how many people can listen at once" is reading them wrong.
|
| ### Choosing the numbers
|
| Free is uncapped on purpose. A cap of 1 there would sign a listener out of
| their phone every time they opened the site on a laptop, before they have
| bought anything — no comparable service does this, and it is a poor first
| impression to charge for. The paid tiers cap at their seat count, which is
| the promise the plans page makes. If a single device turns out to be too
| strict for Standard in practice, this is a one-line change — raise it here
| and the login screen, the device list and the plan copy all follow.
|
*/

return [

    /*
    | How far below the reference price our own sits. 0.20 = 20% less.
    */
    'discount_rate' => 0.20,

    /*
    | Billing currencies. `minor_units` is how many minor units make one major
    | unit — 100 everywhere we launch, but named so a zero-decimal currency
    | (JPY) cannot silently be off by a factor of 100.
    */
    'currencies' => [
        'INR' => ['symbol' => '₹', 'minor_units' => 100],
        'USD' => ['symbol' => '$', 'minor_units' => 100],
    ],

    'default_currency' => 'INR',

    /*
    | Which currency a listener is billed in, by ISO-3166 country on their
    | profile. Anything unlisted falls back to `default_currency`.
    */
    'currency_by_country' => [
        'IN' => 'INR',
    ],

    /*
    | The free tier's entitlements. Not a purchasable plan, so it carries no
    | price — but it must appear in the comparison table, which is why it is
    | described here rather than being an implicit "everything false".
    */
    SubscriptionPlan::Free->value => [
        'name' => 'Free',
        'tagline' => 'Listen with limits.',
        'accounts' => 1,
        // Uncapped — see "Choosing the numbers" above.
        'max_sessions' => null,
        'entitlements' => [
            // Free listeners get shuffle only — the "play songs in any order"
            // row of the comparison table.
            'on_demand_playback' => false,
            'max_audio_quality' => AudioQuality::Normal->value,
            'download' => false,
            'offline_listening' => false,
            /*
             | Building a playlist out of *songs* is free and stays free —
             | taking that away from existing accounts is not what was asked
             | for. This flag is the podcast half of "create a playlist with
             | songs or episodes", and it is currently a promise the catalog
             | cannot keep: the sync has no podcast provider behind it, so
             | there are no episodes to add to anything. The entitlement is
             | wired end to end and the comparison table reads from it; what
             | is missing is content, not plumbing.
             */
            'playlist_with_episodes' => false,
            'ad_free' => false,
            'unlimited_skips' => false,
            'playlist_mixing' => false,
            // Platinum-exclusive — see that plan's own entitlements for why.
            'listen_together' => false,
        ],
    ],

    SubscriptionPlan::Standard->value => [
        'name' => 'Standard',
        'tagline' => 'Listen without limits. Cancel anytime.',
        'accounts' => 1,
        'max_sessions' => 1,
        'reference_price' => ['INR' => 13900, 'USD' => 1199],
        'entitlements' => [
            'on_demand_playback' => true,
            'max_audio_quality' => AudioQuality::VeryHigh->value,
            'download' => true,
            'offline_listening' => true,
            // See the Free tier's note — the podcast half of this is
            // advertised but not yet reachable, because the catalog has no
            // episodes in it.
            'playlist_with_episodes' => true,
            'ad_free' => true,
            'unlimited_skips' => true,
            // Platinum-exclusive — see that plan's own entitlements for why.
            'playlist_mixing' => false,
            // Platinum-exclusive — see that plan's own entitlements for why.
            'listen_together' => false,
        ],
    ],

    SubscriptionPlan::Platinum->value => [
        'name' => 'Platinum',
        'tagline' => 'Everything in Standard, at the highest fidelity.',
        'accounts' => 3,
        'max_sessions' => 3,
        'reference_price' => ['INR' => 29900, 'USD' => 1999],
        'entitlements' => [
            'on_demand_playback' => true,
            // Advertised as lossless; `AudioQuality::clampTo()` degrades it to
            // 320kbps until a provider that serves FLAC exists. See that enum.
            'max_audio_quality' => AudioQuality::Lossless->value,
            'download' => true,
            'offline_listening' => true,
            // See the Free tier's note — the podcast half of this is
            // advertised but not yet reachable, because the catalog has no
            // episodes in it.
            'playlist_with_episodes' => true,
            'ad_free' => true,
            'unlimited_skips' => true,
            /*
             | The entitlements Platinum does *not* share downward to
             | Standard/Student — every other flag in this file is identical
             | across all three paid tiers (the tiers differ in eligibility and
             | audio quality, not features; see Student's own note below).
             | "Mix Your Playlists" is deliberately drawn as Platinum's one
             | exclusive, at explicit product request — it needs no backend
             | enforcement of its own precisely because it is exclusive: the
             | feature has no dedicated endpoint (it only calls the existing
             | playlist read/create/add-song routes, all of which are already
             | reachable by every plan), so the entitlement below is read by
             | the frontend at its single entry point
             | (`LeftSidebar`'s "Mix Your Playlists" menu item) rather than by
             | any controller here.
             */
            'playlist_mixing' => true,
            /*
             | Listen Together, Platinum-exclusive at explicit product
             | request — unlike `playlist_mixing` above, this one *does* have
             | its own endpoints (`ListeningRoomController::store`/`join`), so
             | it is enforced server-side, not just read by the frontend to
             | hide a button. Both sides of a room need this: the host who
             | opens it and every guest who joins by invite link, so a
             | Free/Standard/Student listener cannot ride along on someone
             | else's Platinum room either. See `SubscriptionService::can()`.
             */
            'listen_together' => true,
        ],
    ],

    SubscriptionPlan::Student->value => [
        'name' => 'Student',
        'tagline' => 'Standard, for verified students.',
        'accounts' => 1,
        'max_sessions' => 1,
        'reference_price' => ['INR' => 6900, 'USD' => 599],
        // Identical entitlements to Standard by design — the tiers differ in
        // eligibility, not in what they unlock. See SubscriptionPlan's doc.
        'entitlements' => [
            'on_demand_playback' => true,
            'max_audio_quality' => AudioQuality::VeryHigh->value,
            'download' => true,
            'offline_listening' => true,
            // See the Free tier's note — the podcast half of this is
            // advertised but not yet reachable, because the catalog has no
            // episodes in it.
            'playlist_with_episodes' => true,
            'ad_free' => true,
            'unlimited_skips' => true,
            // Platinum-exclusive — see that plan's own entitlements for why.
            'playlist_mixing' => false,
            // Platinum-exclusive — see that plan's own entitlements for why.
            'listen_together' => false,
        ],
    ],

];
