<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Song;
use App\Services\Reporting\AdminReportingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The admin dashboard landing page: catalog totals, revenue this month vs
 * all-time, a revenue trend chart, and the two "what's happening" song
 * shelves. The full, date-range-filterable version of the same data lives
 * behind {@see AdminReportController}. Every route sits behind
 * ['auth:sanctum', 'admin'].
 */
final class AdminDashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminReportingService $reports,
    ) {}

    /**
     * GET /api/v1/admin/dashboard/summary
     */
    public function summary(): JsonResponse
    {
        $now = Carbon::now();

        return $this->respondSuccess([
            'catalog' => $this->reports->catalogTotals(),
            'active_subscriptions' => $this->reports->activeSubscriptionCount(),
            'revenue_this_month' => $this->reports->revenueByCurrency($now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
            'revenue_all_time' => $this->reports->revenueByCurrency(null, null),
        ]);
    }

    /**
     * GET /api/v1/admin/dashboard/revenue-trend?period=monthly|yearly
     *
     * A fixed, short lookback for the dashboard's chart card — "monthly"
     * shows the last 12 months bucketed by month, "yearly" the last 5 years
     * bucketed by year. An arbitrary custom range is what the Reports page is
     * for.
     */
    public function revenueTrend(Request $request): JsonResponse
    {
        $period = $request->validate([
            'period' => ['sometimes', 'string', 'in:monthly,yearly'],
        ])['period'] ?? 'monthly';

        $now = Carbon::now();

        [$from, $to, $granularity] = $period === 'yearly'
            ? [$now->copy()->subYears(4)->startOfYear(), $now->copy()->endOfYear(), 'year']
            : [$now->copy()->subMonths(11)->startOfMonth(), $now->copy()->endOfMonth(), 'month'];

        return $this->respondSuccess([
            'period' => $period,
            'points' => $this->reports->revenueTrend($from, $to, $granularity),
        ]);
    }

    /**
     * GET /api/v1/admin/dashboard/trending-songs?limit=10
     */
    public function trendingSongs(Request $request): JsonResponse
    {
        $limit = $this->limitFromQuery($request);

        return $this->respondSuccess(
            $this->reports->trendingSongs($limit)->map($this->present(...))->all(),
        );
    }

    /**
     * GET /api/v1/admin/dashboard/new-releases?limit=10
     */
    public function newReleases(Request $request): JsonResponse
    {
        $limit = $this->limitFromQuery($request);

        return $this->respondSuccess(
            $this->reports->newReleases($limit)->map($this->present(...))->all(),
        );
    }

    private function limitFromQuery(Request $request): int
    {
        return min(20, max(1, (int) $request->query('limit', 10)));
    }

    /** @return array<string, mixed> */
    private function present(Song $song): array
    {
        return [
            'id' => $song->id,
            'title' => $song->title,
            'artist' => $song->artist?->name,
            'album' => $song->album?->title,
            'cover_image' => $song->album?->cover_image,
            'popularity' => $song->popularity,
            'trending_score' => $song->trending_score,
            'play_count' => $song->play_count,
            'release_date' => $song->release_date?->toDateString(),
        ];
    }
}
