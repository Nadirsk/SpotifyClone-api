<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Concert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Write side of concerts, for the admin panel — {@see ConcertService} is
 * deliberately read-only, and concerts have no sync source of their own
 * to begin with (first-party, seeded data — see the migration's docblock).
 */
final class AdminConcertService
{
    /**
     * @return LengthAwarePaginator<int, Concert>
     */
    public function paginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        $builder = Concert::query()->with('venue')->orderByDesc('date');

        if ($search !== null && $search !== '') {
            $builder->where('title', 'like', '%'.$search.'%');
        }

        /** @var LengthAwarePaginator<int, Concert> */
        return $builder->paginate(perPage: $limit, page: $page);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Concert
    {
        /** @var Concert */
        return Concert::query()->with('venue')->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Concert
    {
        /** @var Concert $concert */
        $concert = Concert::query()->create($data);

        return $concert->load('venue');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Concert $concert, array $data): Concert
    {
        $concert->update($data);

        return $concert->load('venue');
    }

    public function delete(Concert $concert): void
    {
        $concert->delete();
    }
}
