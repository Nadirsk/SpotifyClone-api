<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Song;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Un-merges albums that hold tracks in more than one language.
 *
 * A film soundtrack is released once per language and JioSaavn publishes each
 * as its own album under the *same* title — "M.S. Dhoni - The Untold Story"
 * exists in Hindi, Telugu, Tamil and Marathi. Every title-based dedup tier
 * matched on title alone, so all four collapsed into one row: that album ended
 * up carrying 26 tracks across four languages, and opening the Hindi
 * soundtrack listed Tamil songs. `DeduplicationService::findAlbum()` and
 * `SyncService::resolveAlbumForSong()` are now language-scoped and will not do
 * it again, but neither un-does the rows already welded together — the songs'
 * `album_id` values still point at the wrong album.
 *
 * This is the one-off repair for that backlog, in the same spirit as
 * `catalog:enrich-artists`: the sync path is fixed going forward, and this
 * fixes what the broken path already wrote.
 *
 * ## What it does, and does not, touch
 *
 * Nothing is ever deleted. For each mixed album the tracks whose language
 * matches the album's stay put, and each other language's tracks move to a
 * sibling album — same title, artist and artwork, with that language set. An
 * existing sibling is reused, so running this twice is not doing it twice.
 *
 * Tracks with no language at all stay where they are. There is nothing to
 * classify them by, and guessing would recreate the exact class of bug this
 * command exists to clean up.
 */
final class SplitMergedAlbums extends Command
{
    protected $signature = 'catalog:split-merged-albums {--dry-run : Report what would change and write nothing} {--limit=0 : Max albums to process, 0 = all} {--min-tracks=3 : Smallest language group worth its own album; smaller ones are left alone}';

    protected $description = 'Split albums holding tracks in several languages into one album per language';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $minTracks = max(1, (int) $this->option('min-tracks'));

        $albumIds = $this->mixedAlbumIds($limit);

        if ($albumIds === []) {
            $this->info('No album holds tracks in more than one language. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d album(s) holding tracks in more than one language.%s',
            $dryRun ? 'Found' : 'Repairing',
            count($albumIds),
            $dryRun ? ' No changes will be written.' : '',
        ));
        $this->newLine();

        $rows = [];
        $movedTotal = 0;
        $createdTotal = 0;
        $reusedTotal = 0;
        $strandedTotal = 0;

        foreach ($albumIds as $albumId) {
            $album = Album::query()->with('language')->find($albumId);

            if ($album === null) {
                continue;
            }

            $plan = $this->planFor($album, $minTracks);

            $strandedTotal += $plan['stranded'];

            if ($plan['groups'] === []) {
                continue;
            }

            foreach ($plan['groups'] as $group) {
                $rows[] = [
                    mb_strimwidth((string) $album->title, 0, 34, '..'),
                    $plan['keeperCode'].' ('.$plan['keptCount'].')',
                    $group['code'],
                    $group['count'],
                    $group['sibling'] === null ? 'create' : 'reuse',
                ];

                $group['sibling'] === null ? $createdTotal++ : $reusedTotal++;
                $movedTotal += $group['count'];
            }

            if (! $dryRun) {
                $this->apply($album, $plan);
            }
        }

        $this->table(['album', 'stays (lang/n)', 'moves', 'tracks', 'sibling'], $rows);

        $this->line(sprintf(
            '%s %d track(s) into %d sibling album(s) (%d new, %d existing).',
            $dryRun ? 'Would move' : 'Moved',
            $movedTotal,
            $createdTotal + $reusedTotal,
            $createdTotal,
            $reusedTotal,
        ));

        if ($strandedTotal > 0) {
            // Said out loud rather than omitted: a silent floor reads as "this
            // album is clean now" when some of its tracks are still misfiled.
            $this->warn(sprintf(
                '%d track(s) in groups smaller than --min-tracks=%d were left in place; those albums stay mixed.',
                $strandedTotal,
                $minTracks,
            ));
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run — nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Albums whose tracks span more than one known language, worst first so a
     * `--limit` run fixes the most visibly broken ones.
     *
     * @return list<string>
     */
    private function mixedAlbumIds(int $limit): array
    {
        $query = Song::query()
            ->selectRaw('album_id, COUNT(DISTINCT language_id) as langs')
            ->whereNotNull('album_id')
            ->whereNotNull('language_id')
            ->groupBy('album_id')
            ->havingRaw('COUNT(DISTINCT language_id) > 1')
            ->orderByDesc('langs');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->pluck('album_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * Which language keeps the album, and which groups move where.
     *
     * The album's own `language_id` wins when its tracks actually include that
     * language. When it does not — or the column was never populated, which is
     * every album `resolveAlbumForSong()` created before this fix — the largest
     * group keeps the row instead: leaving a *minority* language on the original
     * would move more tracks than necessary and strand the album's artwork and
     * release date away from its main tracklist.
     *
     * ## Why groups smaller than `$minTracks` are left alone
     *
     * Not every mixed album is a language-versioned soundtrack. A title generic
     * enough to collide — "Indian", "Pal (Sped Up)" — accumulated unrelated
     * releases through the unscoped core-title tier, and its tracks carry a
     * dozen languages with one track each (`tajik`, `swahili`, `fr`, `ru`,
     * observed on "Indian"). Splitting on that would fragment one bad row into
     * twelve near-empty ones, which is the precise outcome
     * `albumByCoreTitleAnyArtist()`'s docblock says that tier exists to avoid.
     *
     * A real language edition of a film soundtrack has a tracklist, so a size
     * floor separates the two without needing to know which is which. Stragglers
     * below it stay where they are and are counted in `stranded` so the report
     * does not quietly imply the album came out clean.
     *
     * @return array{keeperId: string|null, keeperCode: string, keptCount: int, stranded: int, groups: list<array{code: string, languageId: string, count: int, songIds: list<string>, sibling: Album|null}>}
     */
    private function planFor(Album $album, int $minTracks): array
    {
        $songs = $album->songs()->with('language')->whereNotNull('language_id')->get();

        $byLanguage = $songs->groupBy(static fn (Song $song): string => (string) $song->language_id);

        if ($byLanguage->count() < 2) {
            return ['keeperId' => null, 'keeperCode' => '-', 'keptCount' => 0, 'stranded' => 0, 'groups' => []];
        }

        $albumLanguageId = $album->language_id === null ? null : (string) $album->language_id;

        $keeperId = $albumLanguageId !== null && $byLanguage->has($albumLanguageId)
            ? $albumLanguageId
            : (string) $byLanguage->sortByDesc(static fn ($group): int => $group->count())->keys()->first();

        $groups = [];
        $stranded = 0;

        foreach ($byLanguage as $languageId => $group) {
            if ((string) $languageId === $keeperId) {
                continue;
            }

            if ($group->count() < $minTracks) {
                $stranded += $group->count();

                continue;
            }

            $first = $group->first();

            $groups[] = [
                'code' => (string) ($first->language->code ?? '?'),
                'languageId' => (string) $languageId,
                'count' => $group->count(),
                'songIds' => $group->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all(),
                'sibling' => $this->existingSibling($album, (string) $languageId),
            ];
        }

        $kept = $byLanguage->get($keeperId);
        $keeperSong = $kept->first();

        return [
            'keeperId' => $keeperId,
            'keeperCode' => (string) ($keeperSong->language->code ?? '?'),
            'keptCount' => $kept->count(),
            'stranded' => $stranded,
            'groups' => $groups,
        ];
    }

    /**
     * A sibling this album was already split into, if the command has run
     * before or the sync has since created the language-specific row itself.
     *
     * Matched on artist + slug + language, exactly the tuple
     * `DeduplicationService::albumByTitleAndArtist()` now treats as one album,
     * so the two can never disagree about what counts as a sibling.
     */
    private function existingSibling(Album $album, string $languageId): ?Album
    {
        return Album::query()
            ->where('artist_id', $album->artist_id)
            ->where('slug', $album->slug)
            ->where('language_id', $languageId)
            ->whereKeyNot($album->getKey())
            ->first();
    }

    /**
     * @param  array{keeperId: string|null, keeperCode: string, keptCount: int, groups: list<array{code: string, languageId: string, count: int, songIds: list<string>, sibling: Album|null}>}  $plan
     */
    private function apply(Album $album, array $plan): void
    {
        DB::transaction(function () use ($album, $plan): void {
            /*
             | Stamp the album with the language it is actually keeping. Left
             | null, every language-scoped dedup guard would wave the next
             | language through on its nullable-row allowance and re-merge the
             | album this run just split.
             */
            if ($album->language_id === null && $plan['keeperId'] !== null) {
                $album->language_id = $plan['keeperId'];
                $album->save();
            }

            foreach ($plan['groups'] as $group) {
                $sibling = $group['sibling'] ?? $this->createSibling($album, $group['languageId']);

                Song::query()->whereIn('id', $group['songIds'])->update(['album_id' => $sibling->getKey()]);

                /*
                 | Recounted from the table rather than incremented, so a rerun
                 | or a concurrent sync cannot drift the total.
                 */
                $sibling->total_tracks = Song::query()->where('album_id', $sibling->getKey())->count();
                $sibling->save();
            }

            $album->total_tracks = Song::query()->where('album_id', $album->getKey())->count();
            $album->save();
        });
    }

    /**
     * A copy of the album carrying one language.
     *
     * Everything descriptive is copied because it genuinely is shared — same
     * film, same artwork, same release. What is deliberately not copied is
     * `last_synced_at`: this row has never been near a provider, and claiming
     * otherwise would stop the incremental sync from ever giving it a mapping
     * of its own.
     */
    private function createSibling(Album $album, string $languageId): Album
    {
        return Album::query()->create([
            'artist_id' => $album->artist_id,
            'language_id' => $languageId,
            'title' => $album->title,
            'slug' => $album->slug,
            'description' => $album->description,
            'film_title' => $album->film_title,
            'cover_image' => $album->cover_image,
            'release_date' => $album->release_date,
            'total_tracks' => 0,
            'popularity' => $album->popularity,
            'is_explicit' => $album->is_explicit,
        ]);
    }
}
