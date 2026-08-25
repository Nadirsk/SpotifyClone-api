<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Album;
use App\Models\Genre;
use App\Models\Language;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Genres and languages as *browsable* reference data.
 *
 * The bare lists are just names — enough for a filter dropdown, not enough for
 * the Browse grid, which has to know which taxonomies actually have music
 * behind them and what to put on the tile. So each row here carries two derived
 * facts: how many songs are in it, and one real cover to show for it.
 *
 * Both matter for honesty rather than decoration. `songs.genre_id` is currently
 * null for every row in the catalog — JioSaavn does not label songs by genre —
 * so every genre reports `song_count = 0` and the Browse grid can leave them
 * out instead of rendering twenty tiles that lead nowhere. Language is the
 * taxonomy this catalog really carries (~19k of ~18.5k songs are tagged), and
 * that is what the grid ends up made of.
 *
 * Cost is fixed, not per-tile: two queries for counts and two for covers, no
 * matter how many taxonomies exist. Resolving a cover per tile would be `N+1`
 * across a grid of seventy-odd.
 */
final class TaxonomyService
{
    /**
     * Every genre, alphabetically, with its song count and a cover.
     *
     * Order is deliberately left alphabetical rather than sorted by size: this
     * endpoint still backs the filter dropdown in 05_API_SPECIFICATION §16,
     * where a listener scans for a name. Callers that want the biggest first
     * (the Browse grid) sort on `song_count` themselves.
     *
     * @return EloquentCollection<int, Genre>
     */
    public function genres(): EloquentCollection
    {
        /** @var EloquentCollection<int, Genre> $genres */
        $genres = Genre::query()->orderBy('name')->get();

        $counts = DB::table('songs')
            ->whereNull('deleted_at')
            ->whereNotNull('genre_id')
            ->groupBy('genre_id')
            ->selectRaw('genre_id, count(*) as aggregate')
            ->pluck('aggregate', 'genre_id');

        /*
         | A genre has no cover column of its own, so it borrows one from the
         | most popular album holding a song in it. Null while `genre_id` is
         | unpopulated, which is the truthful answer rather than a stand-in.
         */
        $covers = $this->topCoverPerGroup(
            DB::table('songs')
                ->join('albums', 'albums.id', '=', 'songs.album_id')
                ->whereNull('songs.deleted_at')
                ->whereNull('albums.deleted_at')
                ->whereNotNull('songs.genre_id')
                ->whereNotNull('albums.cover_image')
                ->selectRaw('songs.genre_id as group_id, albums.cover_image, albums.popularity'),
        );

        foreach ($genres as $genre) {
            $genre->song_count = (int) ($counts[$genre->id] ?? 0);
            $genre->cover_image = $covers[$genre->id] ?? null;
        }

        return $genres;
    }

    /**
     * Every language, alphabetically, with its song count and a cover.
     *
     * @return EloquentCollection<int, Language>
     */
    public function languages(): EloquentCollection
    {
        /** @var EloquentCollection<int, Language> $languages */
        $languages = Language::query()->orderBy('name')->get();

        $counts = DB::table('songs')
            ->whereNull('deleted_at')
            ->whereNotNull('language_id')
            ->groupBy('language_id')
            ->selectRaw('language_id, count(*) as aggregate')
            ->pluck('aggregate', 'language_id');

        /*
         | Albums carry `language_id` directly, so this needs no join through
         | songs — the most popular album in a language is the cover for it.
         */
        $covers = $this->topCoverPerGroup(
            DB::table('albums')
                ->whereNull('deleted_at')
                ->whereNotNull('language_id')
                ->whereNotNull('cover_image')
                ->selectRaw('language_id as group_id, cover_image, popularity'),
        );

        foreach ($languages as $language) {
            $language->song_count = (int) ($counts[$language->id] ?? 0);
            $language->cover_image = $covers[$language->id] ?? null;
        }

        return $languages;
    }

    /**
     * The most popular albums in every language, grouped, in one round trip.
     *
     * This exists to kill a fan-out. `/popular-by-country` renders a shelf per
     * language, and the frontend used to build it by calling
     * `/albums?language=X` once per language — eighty parallel requests for one
     * page. The guest rate limit is sixty a minute, so roughly a quarter of
     * those came back `429` on every single load, and the page silently
     * rendered only the languages that happened to win the race (the
     * alphabetically early ones, since that is the order they were issued in).
     * It looked like a catalog with twenty-eight languages in it rather than
     * seventy-one.
     *
     * One `ROW_NUMBER()` pass replaces all of it: rank albums inside each
     * language by popularity, keep the top `$perLanguage`, hydrate those rows
     * with their artist, and group. Two queries total, no rate limit in sight.
     *
     * @return array<string, array{language: Language, albums: EloquentCollection<int, Album>}>
     */
    public function popularAlbumsByLanguage(int $perLanguage = 12): array
    {
        $ranked = DB::query()
            ->fromSub(
                DB::table('albums')
                    ->whereNull('deleted_at')
                    ->whereNotNull('language_id')
                    ->selectRaw(
                        'id, language_id, ROW_NUMBER() OVER (PARTITION BY language_id ORDER BY popularity DESC) as rank_in_group'
                    ),
                'ranked',
            )
            ->where('rank_in_group', '<=', $perLanguage)
            ->orderBy('rank_in_group')
            ->get(['id', 'language_id', 'rank_in_group']);

        if ($ranked->isEmpty()) {
            return [];
        }

        /*
         | Hydrated in one `whereIn` rather than per language, then re-ordered
         | in PHP to the rank the window function already worked out — the
         | database has no reason to return them in that order.
         */
        /** @var EloquentCollection<int, Album> $albums */
        $albums = Album::query()
            ->whereIn('id', $ranked->pluck('id')->all())
            ->with('artist')
            ->get()
            ->keyBy('id');

        /** @var EloquentCollection<int, Language> $languages */
        $languages = Language::query()->orderBy('name')->get()->keyBy('id');

        $grouped = [];

        foreach ($ranked as $row) {
            $language = $languages[$row->language_id] ?? null;
            $album = $albums[$row->id] ?? null;

            if ($language === null || $album === null) {
                continue;
            }

            $code = (string) $language->code;

            if (! isset($grouped[$code])) {
                $grouped[$code] = ['language' => $language, 'albums' => new EloquentCollection()];
            }

            $grouped[$code]['albums']->push($album);
        }

        /*
         | Biggest first, then alphabetical. Insertion order here is whichever
         | language happened to own the first rank-1 row the database handed
         | back, which is arbitrary — it opened the page on Albanian.
         */
        uasort($grouped, static function (array $a, array $b): int {
            return $b['albums']->count() <=> $a['albums']->count()
                ?: strcmp((string) $a['language']->name, (string) $b['language']->name);
        });

        return $grouped;
    }

    /**
     * The highest-popularity `cover_image` in each `group_id`, as one query.
     *
     * `ROW_NUMBER() OVER (PARTITION BY …)` rather than fetching every candidate
     * row and picking the first per group in PHP: the inner set here is tens of
     * thousands of albums, and all but one row per group would be discarded.
     * MariaDB has supported window functions since 10.2 and MySQL since 8.0,
     * both below this project's floor.
     *
     * `$candidates` is wrapped in its own subquery before the window function is
     * added, rather than appending `ROW_NUMBER()` to its select list directly:
     * `group_id` there is a `selectRaw` alias (`songs.genre_id as group_id`, or
     * similar), and neither MySQL nor MariaDB resolve a same-level SELECT-list
     * alias inside a window function's `PARTITION BY` — only a real column of
     * an already-materialized FROM. `popularAlbumsByLanguage()` above never hits
     * this because it partitions by the genuine `language_id` column, not an
     * alias defined in the same select.
     *
     * @param  \Illuminate\Database\Query\Builder  $candidates  Selecting `group_id`, `cover_image`, `popularity`.
     * @return array<string, string>
     */
    private function topCoverPerGroup($candidates): array
    {
        $ranked = DB::query()
            ->fromSub($candidates, 'base')
            ->selectRaw(
                'group_id, cover_image, ROW_NUMBER() OVER (PARTITION BY group_id ORDER BY popularity DESC) as rank_in_group'
            );

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rank_in_group', 1)
            ->get(['group_id', 'cover_image']);

        $covers = [];

        foreach ($rows as $row) {
            $covers[(string) $row->group_id] = (string) $row->cover_image;
        }

        return $covers;
    }
}
