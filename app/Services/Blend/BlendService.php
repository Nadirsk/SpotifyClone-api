<?php

declare(strict_types=1);

namespace App\Services\Blend;

use App\Contracts\Repositories\BlendRepository;
use App\Contracts\Repositories\PlaylistRepository;
use App\Contracts\Repositories\UserRepository;
use App\Enums\PlaylistSource;
use App\Enums\PlaylistVisibility;
use App\Exceptions\DomainException;
use App\Models\Blend;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Blend CRUD, membership and the "Save as Playlist" bridge
 * (12_SCOPE_OF_WORK — no source in 01-11, built on explicit request).
 *
 * Generation itself lives in {@see BlendGenerationService}; this class only
 * decides *when* to call it (on creation there is nothing to generate yet —
 * see `create()` — and on a manual refresh). Authorization lives in
 * {@see \App\Policies\BlendPolicy} and is applied by the controller.
 */
final class BlendService
{
    /**
     * @var list<string>
     */
    private const DETAIL_RELATIONS = [
        'creator',
        'members.user',
        'songs.artist',
        'songs.album',
        'songs.genre',
        'songs.language',
    ];

    public function __construct(
        private readonly BlendRepository $blends,
        private readonly PlaylistRepository $playlists,
        private readonly UserRepository $users,
        private readonly BlendGenerationService $generation,
    ) {}

    /** @return LengthAwarePaginator<int, Blend> */
    public function list(User $viewer, int $page, int $limit): LengthAwarePaginator
    {
        return $this->blends->paginateForUser($viewer, $page, $limit);
    }

    /**
     * The bare model, for the controller to authorize against before a mutation.
     *
     * @throws ModelNotFoundException
     */
    public function find(string $id): Blend
    {
        return $this->blends->findOrFail($id);
    }

    /** @throws ModelNotFoundException */
    public function show(string $id): Blend
    {
        return $this->find($id)->load(self::DETAIL_RELATIONS);
    }

    /**
     * Seats only the creator — a Blend with one member has nothing to
     * combine yet, so generation waits for `BlendInvitationService::accept()`
     * to bring the second member in.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $creator, array $attributes): Blend
    {
        $titleProvided = array_key_exists('title', $attributes) && trim((string) $attributes['title']) !== '';

        $blend = $this->blends->create($creator, [
            'title' => $titleProvided ? $attributes['title'] : "{$creator->name}'s Blend",
            'title_is_default' => ! $titleProvided,
        ]);

        return $blend->load(['creator', 'members.user']);
    }

    public function rename(Blend $blend, string $title): Blend
    {
        return $this->blends->update($blend, ['title' => $title, 'title_is_default' => false]);
    }

    public function delete(Blend $blend): void
    {
        $this->blends->delete($blend);
    }

    /** @throws DomainException When the Blend has fewer than two members. */
    public function regenerate(Blend $blend): Blend
    {
        $this->generation->generate($blend);

        return $this->show((string) $blend->getKey());
    }

    /** @throws DomainException When $user is not a member. */
    public function leave(Blend $blend, User $user): void
    {
        if (! $this->blends->removeMember($blend, $user)) {
            throw DomainException::notABlendMember();
        }
    }

    /**
     * @throws DomainException When $userId is the creator, or is not a member.
     * @throws ModelNotFoundException
     */
    public function removeMember(Blend $blend, string $userId): void
    {
        $user = $this->users->findOrFail($userId);

        if ($blend->isCreator($user)) {
            throw DomainException::cannotRemoveBlendCreator();
        }

        if (! $this->blends->removeMember($blend, $user)) {
            throw DomainException::notABlendMember();
        }
    }

    /**
     * Copies the Blend's current tracklist into a brand-new playlist owned by
     * $requester — any member may do this, not only the creator, mirroring
     * "Save as Playlist" being a personal action in real Spotify Blend.
     * Uses the existing playlist creation path end to end (12_SCOPE_OF_WORK
     * §21: "Use the existing playlist creation API").
     */
    public function saveAsPlaylist(Blend $blend, User $requester): Playlist
    {
        $songs = $this->blends->songs($blend);

        $playlist = $this->playlists->create($requester, [
            'source' => PlaylistSource::User->value,
            'title' => $blend->title,
            'slug' => Str::slug($blend->title),
            'visibility' => PlaylistVisibility::Private->value,
            'is_collaborative' => false,
        ]);

        foreach ($songs as $song) {
            $this->playlists->addSong($playlist, $song);
        }

        $this->playlists->refreshCounters($playlist);

        return $playlist->loadMissing('owner');
    }
}
