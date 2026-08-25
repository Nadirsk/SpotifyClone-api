<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\BlendRepository;
use App\Enums\BlendMemberRole;
use App\Models\Blend;
use App\Models\BlendInvitation;
use App\Models\BlendMember;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentBlendRepository implements BlendRepository
{
    /**
     * Everything BlendResource touches on a listing row. `tracks` is loaded
     * separately by `songs()` only for the detail view — a listing of a
     * user's Blends has no business paying for every member's tracklist.
     *
     * @var list<string>
     */
    private const BASE_RELATIONS = ['creator', 'members.user'];

    public function paginateForUser(User $user, int $page, int $limit): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Blend> */
        return $this->baseQuery()
            ->whereHas('members', function (Builder $members) use ($user): void {
                $members->where('user_id', $user->getKey());
            })
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(perPage: $limit, page: $page);
    }

    public function findOrFail(string $id): Blend
    {
        /** @var Blend */
        return $this->baseQuery()->findOrFail($id);
    }

    public function adminPaginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        $builder = $this->baseQuery()->orderByDesc('created_at');

        if ($search !== null && $search !== '') {
            $builder->where(function (Builder $query) use ($search): void {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhereHas('creator', function (Builder $creator) use ($search): void {
                        $creator->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });
            });
        }

        /** @var LengthAwarePaginator<int, Blend> */
        return $builder->paginate(perPage: $limit, page: $page);
    }

    public function create(User $creator, array $attributes): Blend
    {
        return DB::transaction(function () use ($creator, $attributes): Blend {
            /** @var Blend $blend */
            $blend = Blend::query()->create([
                ...$attributes,
                'created_by' => $creator->getKey(),
            ]);

            $blend->members()->create([
                'user_id' => $creator->getKey(),
                'role' => BlendMemberRole::Creator->value,
                'joined_at' => now(),
            ]);

            // NOT NULL DEFAULT 0 columns outside $fillable — MySQL applies the
            // default, but this in-memory object never learned it without this,
            // same trick as EloquentPlaylistRepository::create().
            $blend->setRawAttributes(array_merge($blend->getAttributes(), [
                'tracks_count' => 0,
                'total_duration' => 0,
            ]));

            $blend->setRelation('creator', $creator);

            return $blend;
        });
    }

    public function update(Blend $blend, array $attributes): Blend
    {
        $blend->fill($attributes)->save();

        return $blend->loadMissing(self::BASE_RELATIONS);
    }

    public function delete(Blend $blend): void
    {
        $blend->delete();
    }

    public function members(Blend $blend): Collection
    {
        return $blend->members()->with('user')->orderBy('created_at')->get();
    }

    public function addMember(Blend $blend, User $user, string $role = 'member'): bool
    {
        if ($blend->isMember($user)) {
            return false;
        }

        $blend->members()->create([
            'user_id' => $user->getKey(),
            'role' => $role,
            'joined_at' => now(),
        ]);

        return true;
    }

    public function removeMember(Blend $blend, User $user): bool
    {
        return $blend->members()->where('user_id', $user->getKey())->delete() > 0;
    }

    public function findInvitation(Blend $blend, User $invitedUser): ?BlendInvitation
    {
        return $blend->invitations()->where('invited_user_id', $invitedUser->getKey())->first();
    }

    public function putInvitation(
        Blend $blend,
        User $invitedBy,
        User $invitedUser,
        string $token,
        ?\DateTimeInterface $expiresAt,
    ): BlendInvitation {
        /** @var BlendInvitation */
        return BlendInvitation::query()->updateOrCreate(
            ['blend_id' => $blend->getKey(), 'invited_user_id' => $invitedUser->getKey()],
            [
                'invited_by' => $invitedBy->getKey(),
                'token' => $token,
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'responded_at' => null,
            ],
        );
    }

    public function findInvitationByToken(string $token): ?BlendInvitation
    {
        return BlendInvitation::query()
            ->where('token', $token)
            ->whereHas('blend')
            ->with(['blend', 'inviter', 'invitedUser'])
            ->first();
    }

    public function markInvitationResponded(BlendInvitation $invitation, string $status): void
    {
        $invitation->update(['status' => $status, 'responded_at' => now()]);
    }

    public function revokeInvitation(BlendInvitation $invitation): void
    {
        $invitation->update(['status' => 'revoked', 'responded_at' => now()]);
    }

    public function songs(Blend $blend): Collection
    {
        /** @var Collection<int, Song> */
        return $blend->songs()->with(['artist', 'album', 'genre', 'language'])->get();
    }

    public function replaceSongs(Blend $blend, array $scoredSongs, ?int $matchScore): void
    {
        DB::transaction(function () use ($blend, $scoredSongs, $matchScore): void {
            DB::table('blend_songs')->where('blend_id', $blend->getKey())->delete();

            $now = now();

            $rows = array_map(
                fn (array $song): array => [
                    'id' => (string) Str::uuid(),
                    'blend_id' => $blend->getKey(),
                    'song_id' => $song['song_id'],
                    'score' => $song['score'],
                    'reason' => $song['reason'],
                    'attributed_user_id' => $song['attributed_user_id'],
                    'position' => $song['position'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $scoredSongs,
            );

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('blend_songs')->insert($chunk);
            }

            $totals = DB::table('blend_songs')
                ->join('songs', 'songs.id', '=', 'blend_songs.song_id')
                ->where('blend_songs.blend_id', $blend->getKey())
                // A soft-deleted song still has a blend_songs row until the
                // next regeneration prunes it via findMany(); it must not be
                // counted in the meantime.
                ->whereNull('songs.deleted_at')
                ->selectRaw('COUNT(*) as tracks, COALESCE(SUM(songs.duration), 0) as duration')
                ->first();

            $blend->forceFill([
                'last_generated_at' => $now,
                'match_score' => $matchScore,
                'tracks_count' => (int) ($totals->tracks ?? 0),
                'total_duration' => (int) ($totals->duration ?? 0),
            ])->save();
        });
    }

    /** @return Builder<Blend> */
    private function baseQuery(): Builder
    {
        return Blend::query()->with(self::BASE_RELATIONS);
    }
}
