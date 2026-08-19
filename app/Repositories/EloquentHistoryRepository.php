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

    public function record(?User $user, Song $song, ?int $msPlayed = null, ?string $sessionId = null): History
    {
        /** @var History */
        return History::query()->create([
            'user_id' => $user?->getKey(),
            // Only kept for a guest: once there is an account, that is the
            // identity, and holding both would be a join between a person and a
            // browser that nothing needs.
            'session_id' => $user === null ? $sessionId : null,
            'song_id' => $song->getKey(),
            'played_at' => now(),
            'ms_played' => $msPlayed,
        ]);
    }

    public function playedRecently(?User $user, Song $song, int $withinMinutes, ?string $sessionId = null): bool
    {
        $since = now()->subMinutes($withinMinutes);

        if ($user !== null) {
            // Column order matches the (user_id, song_id, played_at) index.
            return History::query()
                ->where('user_id', $user->getKey())
                ->where('song_id', $song->getKey())
                ->where('played_at', '>=', $since)
                ->exists();
        }

        if ($sessionId === null) {
            /*
             | An anonymous play with no session identifier cannot be attributed
             | to a listener, so there is nothing to compare it against. Saying
             | "not recently played" is the honest answer; the alternative —
             | treating every such play as a duplicate — would drop them all.
             */
            return false;
        }

        // Matches the (session_id, song_id, played_at) index.
        return History::query()
            ->where('session_id', $sessionId)
            ->where('song_id', $song->getKey())
            ->where('played_at', '>=', $since)
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

    public function topSongIdsSince(DateTimeInterface $since, int $limit): Collection
    {
        $minMs = (int) round(((float) config('music.history.min_play_seconds', 30)) * 1000);

        /** @var Collection<int, string> */
        return History::query()
            ->where('played_at', '>=', $since)
            /*
             | `ms_played` is nullable and was never populated before the listen
             | threshold existed, so a null is counted rather than discarded —
             | dropping it would silently erase the history this table already
             | holds. Anything that *does* report a duration has to clear the bar.
             */
            ->where(static fn ($query) => $query
                ->whereNull('ms_played')
                ->orWhere('ms_played', '>=', $minMs))
            ->groupBy('song_id')
            ->selectRaw('song_id, COUNT(*) as plays')
            ->orderByDesc('plays')
            // Ties broken deterministically, so the same day never reorders
            // between two requests.
            ->orderBy('song_id')
            ->limit($limit)
            ->pluck('song_id');
    }
}
