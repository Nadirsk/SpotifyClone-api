<?php
$file = 'app/Console/Commands/BackfillCredits.php';
$s = file_get_contents($file);
$fails = [];

// run the invariant re-assertion before anything else
$from = "        \$chunk = max(1, min(50, (int) \$this->option('chunk')));";
$to = "        \$healed = \$this->reassertDisplayArtistCredits();\n\n        if (\$healed > 0) {\n            \$this->warn(\"Restored {\$healed} missing display-artist credit(s) before starting.\");\n        }\n\n        \$chunk = max(1, min(50, (int) \$this->option('chunk')));";
if (substr_count($s, $from) !== 1) { $fails[] = 'call'; } else { $s = str_replace($from, $to, $s); }

// the method itself, before targets()
$from = "    /**\n     * The songs to fetch, as a query over their provider mappings.";
$to = <<<'NEW'
    /**
     * Re-assert that every song has a `primary` credit for its display artist.
     *
     * That invariant is what lets {@see \App\Models\Song::scopeCreditedTo()} be a
     * single indexed lookup, and a song missing its row does not fail loudly — it
     * quietly vanishes from its own artist's page. Both writers maintain it
     * ({@see \App\Observers\SongObserver}, {@see CreditWriter}) and a migration
     * seeded the catalog, so this should always find nothing.
     *
     * It exists because it once found nine. A full backfill was in flight while
     * the seeding migration ran, using a build of CreditWriter that replaced a
     * song's credit list without re-seeding the display artist; the two
     * interleaved and nine songs ended up with neither. That exact race cannot
     * recur — the writer now seeds unconditionally — but "the invariant is
     * maintained by three places and verified by none" is the shape of the
     * problem, not the specific race. So the documented repair command checks it,
     * cheaply, every run.
     *
     * `insertOrIgnore` in one statement per batch, keyset-paged the same way the
     * migration is, for the same `max_allowed_packet` reason.
     */
    private function reassertDisplayArtistCredits(): int
    {
        $now = now();
        $after = '';
        $restored = 0;

        while (true) {
            $songs = DB::table('songs')
                ->select('id', 'artist_id')
                ->whereNull('deleted_at')
                ->where('id', '>', $after)
                ->whereNotExists(function ($credit): void {
                    $credit
                        ->from('song_credits')
                        ->whereColumn('song_credits.song_id', 'songs.id')
                        ->whereColumn('song_credits.artist_id', 'songs.artist_id')
                        ->where('song_credits.role', CreditRole::Primary->value)
                        ->selectRaw('1');
                })
                ->orderBy('id')
                ->limit(1000)
                ->get();

            if ($songs->isEmpty()) {
                break;
            }

            $after = (string) $songs->last()->id;
            $rows = [];

            foreach ($songs as $song) {
                if (! is_string($song->artist_id) || $song->artist_id === '') {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'song_id' => $song->id,
                    'artist_id' => $song->artist_id,
                    'role' => CreditRole::Primary->value,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                $restored += DB::table('song_credits')->insertOrIgnore($rows);
            }
        }

        return $restored;
    }

    /**
     * The songs to fetch, as a query over their provider mappings.
NEW;
if (substr_count($s, $from) !== 1) { $fails[] = 'method'; } else { $s = str_replace($from, $to, $s); }

$from = "use Illuminate\Support\Facades\DB;";
if (substr_count($s, $from) !== 1) { $fails[] = 'import'; } else { $s = str_replace($from, "use Illuminate\Support\Facades\DB;\nuse Illuminate\Support\Str;", $s); }

if ($fails !== []) { fwrite(STDERR, "FAILED: ".implode(',', $fails)."\n"); exit(1); }
file_put_contents($file, $s);
echo "ok\n";
