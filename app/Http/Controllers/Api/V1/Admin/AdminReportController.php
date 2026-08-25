<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reporting\AdminReportingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The admin Reports page: catalog totals plus a revenue report over
 * whatever range the admin picks (a month, a year, or an arbitrary custom
 * range) — the date-range-filterable counterpart to
 * {@see AdminDashboardController}'s fixed-lookback dashboard cards. Every
 * route sits behind ['auth:sanctum', 'admin'].
 */
final class AdminReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminReportingService $reports,
    ) {}

    /**
     * GET /api/v1/admin/reports?from=&to=&granularity=day|month|year
     *
     * `from`/`to` default to the current month. `granularity` defaults to
     * whatever reads sensibly for the span: day for two weeks or less, month
     * for up to two years, year beyond that — an explicit value always wins.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'granularity' => ['sometimes', 'string', 'in:day,month,year'],
        ]);

        $now = Carbon::now();
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : $now->copy()->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : $now->copy()->endOfMonth();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $granularity = $validated['granularity'] ?? $this->inferGranularity($from, $to);

        return $this->respondSuccess([
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'granularity' => $granularity,
            ],
            'catalog' => $this->reports->catalogTotals(),
            'active_subscriptions' => $this->reports->activeSubscriptionCount(),
            'revenue' => [
                'by_currency' => $this->reports->revenueByCurrency($from, $to),
                'by_plan' => $this->reports->subscriptionsByPlan($from, $to),
                'trend' => $this->reports->revenueTrend($from, $to, $granularity),
            ],
        ]);
    }

    private function inferGranularity(Carbon $from, Carbon $to): string
    {
        $days = $from->diffInDays($to);

        return match (true) {
            $days <= 31 => 'day',
            $days <= 730 => 'month',
            default => 'year',
        };
    }
}
