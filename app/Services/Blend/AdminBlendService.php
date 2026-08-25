<?php

declare(strict_types=1);

namespace App\Services\Blend;

use App\Contracts\Repositories\BlendRepository;
use App\Models\Blend;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Moderation, not authorship: this only ever lists, inspects and removes
 * Blends the algorithm and its members already generated. There is no
 * create/update here — same reasoning as {@see \App\Services\Library\AdminPlaylistService}.
 */
final class AdminBlendService
{
    public function __construct(
        private readonly BlendRepository $blends,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Blend>
     */
    public function paginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        return $this->blends->adminPaginate($page, $limit, $search);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Blend
    {
        $blend = $this->blends->findOrFail($id);
        $tracks = $this->blends->songs($blend);

        $blend->setRelation('songs', $tracks);

        return $blend;
    }

    public function delete(Blend $blend): void
    {
        $this->blends->delete($blend);
    }
}
