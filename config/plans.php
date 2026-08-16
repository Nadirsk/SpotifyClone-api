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
        ],
    ],

    SubscriptionPlan::Standard->value => [
        'name' => 'Standard',
        'tagline' => 'Listen without limits. Cancel anytime.',
        'accounts' => 1,
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
        ],
    ],

    SubscriptionPlan::Platinum->value => [
        'name' => 'Platinum',
        'tagline' => 'Everything in Standard, at the highest fidelity.',
        'accounts' => 3,
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
        ],
    ],

    SubscriptionPlan::Student->value => [
        'name' => 'Student',
        'tagline' => 'Standard, for verified students.',
        'accounts' => 1,
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
        ],
    ],

];
