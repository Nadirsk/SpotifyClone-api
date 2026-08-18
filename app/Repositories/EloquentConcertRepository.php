<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ConcertRepository;
use App\DTO\ConcertQuery;
use App\Models\Concert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentConcertRepository implements ConcertRepository
{
    public function search(ConcertQuery $query): Collection
    {
        /** @var Collection<int, Concert> */
        return $this->applyFilters(Concert::query()->with('venue'), $query)
            ->orderBy('date')
            ->get();
    }

    public function findOrFail(string $id): Concert
    {
        /** @var Concert */
        return Concert::query()->with('venue')->findOrFail($id);
    }

    /**
     * @param  Builder<Concert>  $builder
     * @return Builder<Concert>
     */
    private function applyFilters(Builder $builder, ConcertQuery $query): Builder
    {
        if ($query->city !== null) {
            $builder->whereHas('venue', fn (Builder $q) => $q->where('city', $query->city));
        }

        if ($query->genre !== null) {
            // MySQL JSON_CONTAINS needs the needle as a JSON-encoded scalar.
            $builder->whereJsonContains('genres', $query->genre);
        }

        if ($query->from !== null) {
            $builder->whereDate('date', '>=', $query->from);
        }

        if ($query->to !== null) {
            $builder->whereDate('date', '<=', $query->to);
        }

        return $builder;
    }
}
