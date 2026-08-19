<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CreditRole;
use App\Models\Song;
use App\Models\SongCredit;
use App\Observers\SongObserver;
use App\Services\Providers\JioSaavn\JioSaavnAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relabels songs whose display artist is credited only off-mic.
 *
 * `songs.artist_id` is the one name a row in a tracklist shows, and for some
 * songs it was the wrong one. JioSaavn does not always tag anybody `singer`, and
 * when it does not, the adapter used to fall back to the first entry in
 * `artists.primary` — which is frequently the lyricist or the composer. "Apna
 * Bana Le" was filed under Amitabh Bhattacharya, who wrote its words, rather
 * than Arijit Singh, who sings it. That is one of the ways an album page came to
 * list songs under the wrong artist.
 *
 * {@see JioSaavnAdapter::performingPrimaryArtist()}
 * stops it happening again by eliminating anyone the provider credits as
 * lyricist, composer or cast before picking a headline name. This repairs the
 * rows written before that existed.
 *
 * ## No provider calls
 *
 * Everything this needs is already in `song_credits`, put there by
 * `catalog:backfill-credits`. It looks for songs where the display artist holds
 * an off-mic credit and no performing credit, while somebody else on the same
 * song does hold one, and moves the label to that person.
 *
 * That is a narrow rule on purpose. It does not touch a song where nobody is
 * credited `singer` — there is no better answer available, and guessing is
 * what produced the wrong labels in the first place. Run
 * `catalog:backfill-credits` first; with no credits stored this correctly finds
 * nothing to do.
 *
 * ## Membership is not touched
 *
 * Only `artist_id` changes. Album, genre, language and track position are left
 * exactly as they are — this is a labelling repair, and the credits that justify
 * it are unaffected by it. {@see SongObserver} keeps the credits
 * table consistent with the new label as each row is saved.
 */
final class RepairDisplayArtists extends Command
{
    protected $signature = 'catalog:repair-display-artists
        {--limit=0 : Max songs to relabel, 0 = no limit}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Relabel songs whose display artist is credited only as lyricist, composer or cast';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $songs = $this->misattributed($limit);

        if ($songs->isEmpty()) {
            $this->info('No song is labelled with an off-mic-only credit. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line(
            ($dryRun ? 'DRY RUN — ' : '')
            ."{$songs->count()} song(s) are labelled with someone credited only off-mic."
        );
        $this->newLine();

        $rows = [];
        $relabelled = 0;

        foreach ($songs as $song) {
            $performer = $this->performerFor($song);

            if ($performer === null) {
                continue;
            }

            $rows[] = [
                mb_substr((string) $song->title, 0, 34),
                mb_substr((string) ($song->current_artist ?? ''), 0, 24),
                mb_substr((string) $performer->artist_name, 0, 24),
                $performer->role,
            ];

            if (! $dryRun) {
                /*
                 | Through the model, not a raw UPDATE, because SongObserver has
                 | to fire: it writes the new display artist's `primary` credit
                 | and clears the old one when that artist has no other credit on
                 | the song. A raw update would leave the credits table stating
                 | the opposite of the songs table.
                 */
                $model = Song::query()->find($song->id);

                if ($model === null) {
                    continue;
                }

                $model->artist_id = (string) $performer->artist_id;
                $model->save();
            }

            $relabelled++;
        }

        $this->table(['song', 'was labelled', 'now labelled', 'on the strength of'], array_slice($rows, 0, 25));

        if (count($rows) > 25) {
            $this->line('  ... '.(count($rows) - 25).' more.');
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would relabel ' : 'Relabelled ')."{$relabelled} song(s).");

        $skipped = $songs->count() - $relabelled;

        if ($skipped > 0) {
            // Should be zero — the query already requires a performer to exist —
            // so a non-zero count means something raced and is worth seeing.
            $this->warn("{$skipped} song(s) were skipped because no performing credit could be read back.");
        }

        if ($dryRun) {
            $this->line('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * Songs whose display artist is credited off-mic only, where a performer exists.
     *
     * @return Collection<int, object>
     */
    private function misattributed(int $limit): Collection
    {
        $offMic = [CreditRole::Lyricist->value, CreditRole::Composer->value, CreditRole::Actor->value];
        $onMic = [CreditRole::Singer->value, CreditRole::Featured->value];

        $query = DB::table('songs')
            ->join('artists', 'artists.id', '=', 'songs.artist_id')
            ->whereNull('songs.deleted_at')
            // The display artist holds an off-mic credit on this song ...
            ->whereExists(fn ($q) => $q->from('song_credits')
                ->whereColumn('song_credits.song_id', 'songs.id')
                ->whereColumn('song_credits.artist_id', 'songs.artist_id')
                ->whereIn('song_credits.role', $offMic)->selectRaw('1'))
            // ... and holds no performing credit on it ...
            ->whereNotExists(fn ($q) => $q->from('song_credits')
                ->whereColumn('song_credits.song_id', 'songs.id')
                ->whereColumn('song_credits.artist_id', 'songs.artist_id')
                ->whereIn('song_credits.role', $onMic)->selectRaw('1'))
            /*
             | ... while somebody else on the same song is credited `singer`
             | specifically, not merely performing.
             |
             | `featured` is enough to protect the current label (above) and not
             | enough to take it. A guest vocalist is by definition not the
             | headline, and accepting one produced visibly wrong answers in the
             | dry run: "Namo Namo" moved off Amit Trivedi onto an obscure guest.
             | Amit Trivedi sings that song — the provider simply tags him
             | `music` and nobody `singer`, so the rule had no evidence either way
             | and should not have acted. Requiring an explicit singer credit
             | means the repair only fires where the provider says who sang.
             */
            ->whereExists(fn ($q) => $q->from('song_credits')
                ->whereColumn('song_credits.song_id', 'songs.id')
                ->where('song_credits.role', CreditRole::Singer->value)->selectRaw('1'))
            ->orderBy('songs.id')
            ->select('songs.id', 'songs.title', 'artists.name as current_artist');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * The singer to move the label to.
     *
     * Restricted to an explicit `singer` credit — see the note in
     * {@see misattributed()} on why a `featured` guest does not qualify. Ordered
     * by the position the provider listed them in, which is the order a credits
     * block prints, so a duet resolves to the first-billed voice rather than an
     * arbitrary row.
     */
    private function performerFor(object $song): ?object
    {
        return DB::table('song_credits')
            ->join('artists', 'artists.id', '=', 'song_credits.artist_id')
            ->where('song_credits.song_id', $song->id)
            ->where('song_credits.role', CreditRole::Singer->value)
            ->whereNull('artists.deleted_at')
            ->orderByRaw(SongCredit::roleWeightOrdering('song_credits.role'))
            ->orderBy('song_credits.position')
            ->select('song_credits.artist_id', 'song_credits.role', 'artists.name as artist_name')
            ->first();
    }
}
