<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTO\ConcertQuery;
use App\Models\Concert;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface ConcertRepository
{
    /** @return Collection<int, Concert> */
    public function search(ConcertQuery $query): Collection;

    /** @throws ModelNotFoundException */
    public function findOrFail(string $id): Concert;
}
