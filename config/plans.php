<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Billing policy
|--------------------------------------------------------------------------
|
| Site-wide billing settings. What each *plan* costs and unlocks — name,
| tagline, seats, device cap, reference price, entitlements — now lives in
| the `plans` database table (see that migration and `App\Models\Plan`),
| editable from the admin panel without a deploy. `App\Services\Billing\PlanCatalog`
| is the only place that reads both this file and that table.
|
| ## On pricing
|
| The commercial rule is "20% below Spotify", so each plan row stores the
| *reference* price and our price is *derived* from it at read time
| (`PlanCatalog::priceFor()`), using the `discount_rate` below. Storing both
| would let the two drift and make the discount unverifiable.
|
| `discount_rate` is the knob if the 20% ever moves — it applies to every
| plan uniformly, which is why it stays a single site-wide setting here
| rather than a per-plan column.
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

];
