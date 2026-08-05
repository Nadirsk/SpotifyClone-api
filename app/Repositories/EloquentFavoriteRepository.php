<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\FavoriteRepository;
use App\Models\Favorite;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentFavoriteRepository implements FavoriteRepository
{
    public function paginateForUser(User $user, int $page, int $limit): LengthAwarePaginator
    {
        /*
         | The endpoint returns songs, not favourite rows, but the ordering
         | belongs to `favorites`. Joining and selecting `songs.*` keeps that in
         | one query instead of paginating favourites and re-fetching songs.
         */
        /** @var LengthAwarePaginator<int, Song> */
        return Song::query()
            ->with(EloquentSongRepository::RELATIONS)
            ->join('favorites', 'favorites.song_id', '=', 'songs.id')
            ->where('favorites.user_id', $user->getKey())
            ->select('songs.*')
            ->orderByDesc('favorites.created_at')
            ->orderBy('songs.id')
            ->paginate(perPage: $limit, page: $page);
    }

    public function add(User $user, Song $song): bool
    {
        // Checked rather than caught: re-favouriting is a routine no-op, not an
        // exceptional condition. The unique index remains the backstop.
        if ($this->exists($user, $song)) {
            return false;
        }

        Favorite::query()->create([
            'user_id' => $user->getKey(),
            'song_id' => $song->getKey(),
        ]);

        return true;
    }

    public function remove(User $user, Song $song): bool
    {
        return $this->favoriteQuery($user, $song)->delete() > 0;
    }

    public function exists(User $user, Song $song): bool
    {
        return $this->favoriteQuery($user, $song)->exists();
    }

    public function favoritedIds(User $user, array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }

        /** @var list<string> */
        return Favorite::query()
            ->where('user_id', $user->getKey())
            ->whereIn('song_id', $songIds)
            ->pluck('song_id')
            ->values()
            ->all();
    }

    /** @return Builder<Favorite> */
    private function favoriteQuery(User $user, Song $song): Builder
    {
        return Favorite::query()
            ->where('user_id', $user->getKey())
            ->where('song_id', $song->getKey());
    }
}
