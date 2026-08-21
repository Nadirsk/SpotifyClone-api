<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\AudioQuality;
use App\Enums\SubscriptionPlan;
use InvalidArgumentException;

/**
 * Reads `config/plans.php` and answers everything about what a tier costs and
 * what it unlocks.
 *
 * Stateless and free of database access on purpose: pricing is configuration,
 * not data, so this can be resolved anywhere — including inside a validation
 * rule — without a query. `SubscriptionService` is the piece that knows which
 * plan a *user* is on; this one only knows what the plans themselves are.
 *
 * ## The discount
 *
 * Our price is always computed as `reference × (1 − discount_rate)`, never
 * stored. That is what keeps the "20% below Spotify" claim on the plans page
 * true by construction: there is no second number that can be edited out of
 * sync with the first. Rounding is `intdiv`-style on minor units, so ₹139.00
 * becomes ₹111.20 rather than a float that prints as ₹111.19999999.
 */
final class PlanCatalog
{
    /**
     * Every plan, free first, in the order the comparison table shows them.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $currency): array
    {
        $this->assertCurrency($currency);

        return array_map(
            fn (SubscriptionPlan $plan): array => $this->describe($plan, $currency),
            [SubscriptionPlan::Free, ...SubscriptionPlan::purchasable()],
        );
    }

    /** @return array<string, mixed> */
    public function describe(SubscriptionPlan $plan, string $currency): array
    {
        $this->assertCurrency($currency);

        $config = $this->config($plan);
        $reference = $config['reference_price'][$currency] ?? null;

        return [
            'plan' => $plan->value,
            'name' => $config['name'],
            'label' => $plan->label(),
            'tagline' => $config['tagline'],
            'accounts' => $config['accounts'],
            'max_sessions' => $this->maxSessions($plan),
            'currency' => $currency,
            'symbol' => $this->symbol($currency),
            /*
             | Free carries no price block at all rather than a zero one — a
             | "₹0.00, 20% off ₹0.00" row would be nonsense in the comparison
             | table, and the frontend keys off the null to render "Free".
             */
            'price' => $reference === null ? null : $this->priceBlock((int) $reference, $currency),
            'entitlements' => $this->entitlements($plan),
        ];
    }

    /** The price a listener actually pays, in minor units. */
    public function priceFor(SubscriptionPlan $plan, string $currency): int
    {
        $this->assertCurrency($currency);

        $reference = $this->config($plan)['reference_price'][$currency] ?? null;

        if ($reference === null) {
            // Free, or a plan not sold in this market. Either way, nothing to charge.
            return 0;
        }

        return $this->discounted((int) $reference);
    }

    /**
     * @return array<string, mixed> The plan's entitlement map, exactly as the
     *                              gates read it.
     */
    public function entitlements(SubscriptionPlan $plan): array
    {
        return $this->config($plan)['entitlements'];
    }

    public function entitles(SubscriptionPlan $plan, string $capability): bool
    {
        return (bool) ($this->entitlements($plan)[$capability] ?? false);
    }

    /** The best quality tier this plan is allowed to stream or download. */
    public function maxQuality(SubscriptionPlan $plan): AudioQuality
    {
        return AudioQuality::from($this->entitlements($plan)['max_audio_quality']);
    }

    /**
     * How many devices may hold a live token on this plan at once. `null` is
     * uncapped, which is what the Free tier is.
     *
     * Deliberately not inside `entitlements`: that map is a set of boolean-ish
     * capability flags the frontend renders as comparison-table rows, and a
     * nullable integer read by exactly one service does not belong in it. See
     * `config/plans.php` for why this is not the same number as `accounts`, and
     * for what it cannot do (it caps logins, not streams).
     */
    public function maxSessions(SubscriptionPlan $plan): ?int
    {
        $max = $this->config($plan)['max_sessions'] ?? null;

        return $max === null ? null : (int) $max;
    }

    /**
     * Which currency to bill a listener in, from the ISO-3166 country on their
     * profile. Unknown or missing country falls back to the configured default
     * rather than refusing to quote a price.
     */
    public function currencyForCountry(?string $country): string
    {
        $map = config('plans.currency_by_country');
        $default = config('plans.default_currency');

        if ($country === null) {
            return $default;
        }

        return $map[strtoupper($country)] ?? $default;
    }

    public function isSupportedCurrency(string $currency): bool
    {
        return array_key_exists(strtoupper($currency), config('plans.currencies'));
    }

    /** @return list<string> */
    public function currencies(): array
    {
        return array_keys(config('plans.currencies'));
    }

    public function symbol(string $currency): string
    {
        return config("plans.currencies.{$currency}.symbol", '');
    }

    /**
     * Both numbers the plans page needs: what we charge, and the reference it
     * undercuts. Formatted strings are built here rather than in the frontend
     * so the symbol, the decimal places and the discount all come from one
     * place and cannot disagree between the card and the comparison table.
     *
     * @return array<string, mixed>
     */
    private function priceBlock(int $reference, string $currency): array
    {
        $ours = $this->discounted($reference);
        $rate = (float) config('plans.discount_rate');

        return [
            'amount_minor' => $ours,
            'amount' => $this->format($ours, $currency),
            'reference_amount_minor' => $reference,
            'reference_amount' => $this->format($reference, $currency),
            'reference_label' => 'Spotify',
            'discount_percent' => (int) round($rate * 100),
            'saving' => $this->format($reference - $ours, $currency),
            'period' => 'month',
        ];
    }

    private function discounted(int $referenceMinor): int
    {
        $rate = (float) config('plans.discount_rate');

        // Round half-up on the minor unit — never floor, which would quietly
        // hand out an extra fraction of a paisa of discount on every plan.
        return (int) round($referenceMinor * (1.0 - $rate));
    }

    private function format(int $minor, string $currency): string
    {
        $units = (int) config("plans.currencies.{$currency}.minor_units", 100);
        $decimals = $units === 1 ? 0 : (int) log10($units);

        return $this->symbol($currency).number_format($minor / $units, $decimals);
    }

    /** @return array<string, mixed> */
    private function config(SubscriptionPlan $plan): array
    {
        $config = config("plans.{$plan->value}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Plan [{$plan->value}] is not configured.");
        }

        return $config;
    }

    private function assertCurrency(string $currency): void
    {
        if (! $this->isSupportedCurrency($currency)) {
            throw new InvalidArgumentException("Currency [{$currency}] is not supported.");
        }
    }
}
