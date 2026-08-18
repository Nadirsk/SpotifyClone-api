<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Contracts\Repositories\ConcertRepository;
use App\DTO\ConcertQuery;
use App\Models\Concert;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ConcertService
{
    public function __construct(
        private readonly ConcertRepository $concerts,
    ) {}

    /** @return Collection<int, Concert> */
    public function search(ConcertQuery $query): Collection
    {
        return $this->concerts->search($query);
    }

    /** @throws ModelNotFoundException */
    public function find(string $id): Concert
    {
        return $this->concerts->findOrFail($id);
    }
}
