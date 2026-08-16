<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Song;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Soundtracks browse hub: film music, grouped by the film it is from.
 *
 * A *derived* index, not a stored one. `films()` groups over the parsed
 * `film_title` column rather than over a `films` table, because a film is not
 * an entity this platform syncs, owns or can enrich — it is a fact recovered
 * from song titles (see `SoundtrackParser`). Materialising it into a table
 * would create rows nothing keeps up to date and an id space with no provider
 * mapping behind it.
 *
 * The cost of that choice is that a film is addressed by its name, so the route
 * key is the name itself rather than a UUID. Acceptable here: names come from a
 * controlled parse, and any id we invented would just be a hash of the same
 * string.
 */
final class SoundtrackService
{
    /** What SongResource nests — see PlaylistService::TRACK_RELATIONS. */
    private const SONG_RELATIONS = ['artist', 'album', 'genre', 'language'];

    /**
     * Films that have soundtrack recordings, most popular first.
     *
     * Two queries regardless of page size — one to group, one to fetch a cover
     * for each film on the page. Resolving a cover per card would be `N+1` on a
     * grid that shows 50 of them.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function films(int $page, int $limit): LengthAwarePaginator
    {
        /** @var EloquentCollection<int, Song> $rows */
        $rows = Song::query()
            ->whereNotNull('film_title')
            ->groupBy('film_title')
            ->selectRaw('film_title, COUNT(*) as track_count, MAX(popularity) as top_popularity, MAX(release_date) as latest_release')
            ->orderByDesc('top_popularity')
            ->orderBy('film_title')
            ->forPage($page, $limit)
            ->get();

        $covers = $this->coversFor($rows->pluck('film_title')->filter()->all());

        return new Paginator(
            items: $rows->map(fn (Song $row): array => [
                'film' => (string) $row->film_title,
                'track_count' => (int) $row->track_count,
                'latest_release' => $row->latest_release !== null ? (string) $row->latest_release : null,
                'cover_image' => $covers[(string) $row->film_title] ?? null,
            ])->all(),
            // `count(distinct)` over the grouped column — a plain `count()` on
            // the grouped query returns per-group counts, not the group count.
            total: $this->filmCount(),
            perPage: $limit,
            currentPage: $page,
        );
    }

    /**
     * One film's recordings, most popular first.
     *
     * @return array<string, mixed>
     *
     * @throws NotFoundHttpException
     */
    public function film(string $film, int $limit): array
    {
        /** @var EloquentCollection<int, Song> $songs */
        $songs = Song::query()
            ->where('film_title', $film)
            ->with(self::SONG_RELATIONS)
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();

        if ($songs->isEmpty()) {
            throw new NotFoundHttpException('No soundtrack found for that title.');
        }

        $cover = $songs->first(static fn (Song $song): bool => $song->album?->cover_image !== null);

        return [
            'film' => $film,
            'track_count' => $songs->count(),
            'cover_image' => $cover?->album?->cover_image,
            'artists' => $songs
                ->map(static fn (Song $song): ?string => $song->artist?->name)
                ->filter()
                ->unique()
                ->take(5)
                ->values()
                ->all(),
            'songs' => $songs,
        ];
    }

    /** How many films the hub knows about. */
    public function filmCount(): int
    {
        return Song::query()->whereNotNull('film_title')->distinct()->count('film_title');
    }

    /**
     * The album cover of each film's most popular track, in one query.
     *
     * @param  list<string>  $films
     * @return array<string, string>
     */
    private function coversFor(array $films): array
    {
        if ($films === []) {
            return [];
        }

        /** @var EloquentCollection<int, Song> $songs */
        $songs = Song::query()
            ->whereIn('film_title', $films)
            ->whereNotNull('album_id')
            ->with('album:id,cover_image')
            ->orderByDesc('popularity')
            ->get(['id', 'film_title', 'album_id', 'popularity']);

        $covers = [];

        foreach ($songs as $song) {
            $film = (string) $song->film_title;
            $cover = $song->album?->cover_image;

            // First wins — the collection is already ordered by popularity.
            if ($cover !== null && ! isset($covers[$film])) {
                $covers[$film] = $cover;
            }
        }

        return $covers;
    }
}
