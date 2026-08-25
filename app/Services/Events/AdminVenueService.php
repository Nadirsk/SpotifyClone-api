<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Venue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Write side of venues, for the admin panel. Venue has no repository of
 * its own — {@see VenueController} reads it directly via Eloquent, same
 * reasoning as Genre — so this follows that same simpler shape.
 */
final class AdminVenueService
{
    /**
     * @return LengthAwarePaginator<int, Venue>
     */
    public function paginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        $builder = Venue::query()->orderBy('name');

        if ($search !== null && $search !== '') {
            $builder->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('city', 'like', '%'.$search.'%');
            });
        }

        /** @var LengthAwarePaginator<int, Venue> */
        return $builder->paginate(perPage: $limit, page: $page);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Venue
    {
        /** @var Venue */
        return Venue::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Venue
    {
        return Venue::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Venue $venue, array $data): Venue
    {
        $venue->update($data);

        return $venue;
    }

    /**
     * Cascades: the `concerts.venue_id` foreign key is `cascadeOnDelete()`,
     * so every concert at this venue is removed with it. The controller is
     * what surfaces that consequence to the caller before this runs.
     */
    public function delete(Venue $venue): void
    {
        $venue->delete();
    }
}
