<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\SearchHistoryRepository;
use App\Models\SearchHistory;
use App\Models\User;
use Illuminate\Support\Collection;

final class EloquentSearchHistoryRepository implements SearchHistoryRepository
{
    public function record(?User $user, string $keyword, int $resultsCount): void
    {
        SearchHistory::query()->create([
            // Null for guests: their queries still feed the popular-search list.
            'user_id' => $user?->getKey(),
            'keyword' => $keyword,
            'results_count' => $resultsCount,
            'searched_at' => now(),
        ]);
    }

    public function recentForUser(User $user, int $limit): Collection
    {
        /*
         | Grouped rather than DISTINCT-ordered: a keyword searched five times
         | should appear once, at the position of its most recent search.
         */
        /** @var Collection<int, string> */
        return SearchHistory::query()
            ->where('user_id', $user->getKey())
            ->selectRaw('keyword, MAX(searched_at) as last_searched_at')
            ->groupBy('keyword')
            ->orderByDesc('last_searched_at')
            ->limit($limit)
            ->pluck('keyword');
    }

    public function popular(int $limit, int $withinDays): Collection
    {
        /** @var Collection<int, string> */
        return SearchHistory::query()
            ->where('searched_at', '>=', now()->subDays($withinDays))
            ->selectRaw('keyword, COUNT(*) as searches')
            ->groupBy('keyword')
            ->orderByDesc('searches')
            ->limit($limit)
            ->pluck('keyword');
    }
}
