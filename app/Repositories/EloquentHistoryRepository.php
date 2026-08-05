<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\HistoryRepository;
use App\Models\History;
use App\Models\Song;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class EloquentHistoryRepository implements HistoryRepository
{
    /**
     * The nested paths also load `song` itself, so HistoryResource can reach the
     * full song payload without a lazy load.
     *
     * @var list<string>
     */
    private const RELATIONS = ['song.artist', 'song.album', 'song.genre', 'song.language'];

    public function paginateForUser(User $user, int $page, int $limit): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, History> */
        return History::query()
            ->with(self::RELATIONS)
            ->where('user_id', $user->getKey())
            ->orderByDesc('played_at')
            ->orderBy('id')
            ->paginate(perPage: $limit, page: $page);
    }

    public function record(User $user, Song $song, ?int $msPlayed = null): History
    {
        /** @var History */
        return History::query()->create([
            'user_id' => $user->getKey(),
            'song_id' => $song->getKey(),
            'played_at' => now(),
            'ms_played' => $msPlayed,
        ]);
    }

    public function playedRecently(User $user, Song $song, int $withinMinutes): bool
    {
        // Column order matches the (user_id, song_id, played_at) index.
        return History::query()
            ->where('user_id', $user->getKey())
            ->where('song_id', $song->getKey())
            ->where('played_at', '>=', now()->subMinutes($withinMinutes))
            ->exists();
    }

    public function clear(User $user): void
    {
        History::query()->where('user_id', $user->getKey())->delete();
    }

    public function playCountsSince(DateTimeInterface $since): Collection
    {
        /** @var Collection<string, int> */
        return History::query()
            ->where('played_at', '>=', $since)
            ->groupBy('song_id')
            ->selectRaw('song_id, COUNT(*) as plays')
            ->pluck('plays', 'song_id')
            // COUNT() comes back as a string on some MySQL/PDO builds.
            ->map(static fn (mixed $plays): int => (int) $plays);
    }
}
