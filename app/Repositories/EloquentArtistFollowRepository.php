<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ArtistFollowRepository;
use App\Models\Artist;
use App\Models\ArtistFollow;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentArtistFollowRepository implements ArtistFollowRepository
{
    public function paginateForUser(User $user, int $page, int $limit): LengthAwarePaginator
    {
        /*
         | Mirrors EloquentFavoriteRepository::paginateForUser() — the endpoint
         | returns artists, not follow rows, but the ordering belongs to
         | `artist_follows`. Joining and selecting `artists.*` keeps that in one
         | query instead of paginating follows and re-fetching artists.
         */
        /** @var LengthAwarePaginator<int, Artist> */
        return Artist::query()
            ->join('artist_follows', 'artist_follows.artist_id', '=', 'artists.id')
            ->where('artist_follows.user_id', $user->getKey())
            ->select('artists.*')
            ->orderByDesc('artist_follows.created_at')
            ->orderBy('artists.id')
            ->paginate(perPage: $limit, page: $page);
    }

    public function add(User $user, Artist $artist): bool
    {
        // Checked rather than caught: re-following is a routine no-op, not an
        // exceptional condition. The unique index remains the backstop.
        if ($this->exists($user, $artist)) {
            return false;
        }

        ArtistFollow::query()->create([
            'user_id' => $user->getKey(),
            'artist_id' => $artist->getKey(),
        ]);

        return true;
    }

    public function remove(User $user, Artist $artist): bool
    {
        return $this->followQuery($user, $artist)->delete() > 0;
    }

    public function exists(User $user, Artist $artist): bool
    {
        return $this->followQuery($user, $artist)->exists();
    }

    /** @return Builder<ArtistFollow> */
    private function followQuery(User $user, Artist $artist): Builder
    {
        return ArtistFollow::query()
            ->where('user_id', $user->getKey())
            ->where('artist_id', $artist->getKey());
    }
}
