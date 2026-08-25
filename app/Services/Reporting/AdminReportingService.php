<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\SubscriptionStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Blend;
use App\Models\Concert;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Read-only aggregates for the admin dashboard and the Reports page. Nothing
 * here mutates state; it only counts and sums what the catalog controllers
 * and {@see \App\Services\Billing\SubscriptionService} have already written.
 *
 * ## "Revenue" is the only money concept this app has
 *
 * `subscriptions.amount_minor` is what a simulated checkout charged at the
 * moment a plan was bought — see that migration and `SubscriptionService`'s
 * "Checkout is simulated" note. Nothing in this app tracks a cost of goods,
 * royalties, or infrastructure spend, so there is no "profit" figure
 * distinguishable from revenue; every total below is revenue, deliberately
 * never relabelled as profit.
 *
 * A charge is counted once, on the row's `created_at` — a cancellation never
 * removes it (there is no refund concept), so "revenue in March" means "what
 * was charged in March", not "what is still active today". Money is always
 * kept split by currency rather than summed together, the same rule
 * `PlanCatalog` follows for a single price.
 */
final class AdminReportingService
{
    /** @return array<string, int> */
    public function catalogTotals(): array
    {
        return [
            'songs' => Song::query()->count(),
            'albums' => Album::query()->count(),
            'artists' => Artist::query()->count(),
            'genres' => Genre::query()->count(),
            'languages' => Language::query()->count(),
            'playlists' => Playlist::query()->count(),
            'blends' => Blend::query()->count(),
            'concerts' => Concert::query()->count(),
            'venues' => Venue::query()->count(),
            'users' => User::query()->count(),
        ];
    }

    /**
     * Revenue charged in [from, to] (inclusive), summed per currency in minor
     * units. Either bound may be null for an open end.
     *
     * @return array<string, int>
     */
    public function revenueByCurrency(?Carbon $from, ?Carbon $to): array
    {
        $query = Subscription::query();
        $this->applyRange($query, $from, $to);

        return $query
            ->selectRaw('currency, SUM(amount_minor) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * How many subscriptions were bought in [from, to], per plan.
     *
     * @return array<string, int>
     */
    public function subscriptionsByPlan(?Carbon $from, ?Carbon $to): array
    {
        $query = Subscription::query();
        $this->applyRange($query, $from, $to);

        return $query
            ->selectRaw('plan, COUNT(*) as total')
            ->groupBy('plan')
            ->pluck('total', 'plan')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /** Entitling right now — mirrors {@see \App\Models\Subscription::isEntitled()} in bulk. */
    public function activeSubscriptionCount(): int
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where(function (Builder $query): void {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>', Carbon::now());
            })
            ->count();
    }

    /**
     * Revenue charged per time bucket across [from, to] — one row per
     * currency per bucket, since a chart cannot collapse two currencies into
     * one line without lying about the total.
     *
     * @return list<array{bucket: string, currency: string, total_minor: int}>
     */
    public function revenueTrend(Carbon $from, Carbon $to, string $granularity): array
    {
        $bucketExpr = match ($granularity) {
            'day' => 'DATE(created_at)',
            'month' => "DATE_FORMAT(created_at, '%Y-%m')",
            'year' => 'YEAR(created_at)',
            default => throw new InvalidArgumentException("Unknown granularity [{$granularity}]."),
        };

        return Subscription::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("{$bucketExpr} as bucket, currency, SUM(amount_minor) as total")
            ->groupBy('bucket', 'currency')
            ->orderBy('bucket')
            ->get()
            ->map(static fn (object $row): array => [
                'bucket' => (string) $row->bucket,
                'currency' => (string) $row->currency,
                'total_minor' => (int) $row->total,
            ])
            ->all();
    }

    /** @return Collection<int, Song> */
    public function trendingSongs(int $limit): Collection
    {
        return Song::query()
            ->with(['artist', 'album'])
            ->orderByDesc('trending_score')
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Song> */
    public function newReleases(int $limit): Collection
    {
        return Song::query()
            ->with(['artist', 'album'])
            ->whereNotNull('release_date')
            ->orderByDesc('release_date')
            ->limit($limit)
            ->get();
    }

    /** @param  Builder<Subscription>  $query */
    private function applyRange(Builder $query, ?Carbon $from, ?Carbon $to): void
    {
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }
    }
}
