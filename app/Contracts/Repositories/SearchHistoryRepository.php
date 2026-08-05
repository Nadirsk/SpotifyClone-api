<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;

interface SearchHistoryRepository
{
    public function record(?User $user, string $keyword, int $resultsCount): void;

    /**
     * The user's recent distinct keywords, for the "recent searches" list.
     *
     * @return Collection<int, string>
     */
    public function recentForUser(User $user, int $limit): Collection;

    /**
     * Most-searched keywords across all users in the trailing window.
     *
     * @return Collection<int, string>
     */
    public function popular(int $limit, int $withinDays): Collection;
}
