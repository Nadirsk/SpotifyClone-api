<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlaylistVisibility;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test accounts with a populated library: favorites, listening history and
 * playlists.
 *
 * History is deliberately spread unevenly over the last 14 days and weighted
 * toward popular songs — trending is a time-decayed play count, so a flat
 * distribution would give every song the same score and make the endpoint
 * impossible to eyeball.
 *
 * Re-running wipes each seeded user's own library first, so the counts stay
 * right instead of doubling.
 */
class UserSeeder extends Seeder
{
    private const CHUNK_SIZE = 500;

    private const HISTORY_WINDOW_DAYS = 14;

    /**
     * name, email, ISO country, UI language.
     *
     * The first entry is the documented manual-testing account.
     *
     * @var list<array{string, string, string, string}>
     */
    private const USERS = [
        ['Demo Listener', 'demo@musicdiscovery.test', 'US', 'en'],
        ['Ava Lindqvist', 'ava@musicdiscovery.test', 'SE', 'en'],
        ['Rohan Malhotra', 'rohan@musicdiscovery.test', 'IN', 'hi'],
        ['Simran Gill', 'simran@musicdiscovery.test', 'IN', 'pa'],
        ['Marco Ruiz', 'marco@musicdiscovery.test', 'ES', 'es'],
        ['Yuki Tanaka', 'yuki@musicdiscovery.test', 'JP', 'ja'],
        ['Minji Park', 'minji@musicdiscovery.test', 'KR', 'ko'],
        ['Chloe Bennett', 'chloe@musicdiscovery.test', 'GB', 'en'],
    ];

    /** @var list<string> */
    private const PLAYLIST_TITLES = [
        'Late Night Drive', 'Morning Coffee', 'Workout Fuel', 'Focus Flow',
        'Rainy Day', 'Throwback Hits', 'Party Starters', 'Chill Evenings',
        'Road Trip', 'Study Session', 'Weekend Warmup', 'Deep Cuts',
    ];

    /** @var list<string> */
    private const SEARCH_KEYWORDS = [
        'neon harbour', 'cassidy', 'hip hop', 'sad songs', 'aarav mehta',
        'punjabi', 'workout', 'imagin dragons', 'k-pop 2026', 'jazz piano',
        'lo fi', 'midnight', 'beliver', 'summer hits', 'acoustic covers',
    ];

    public function run(): void
    {
        $songs = $this->songPool();

        if ($songs === []) {
            $this->command?->warn('No songs found — run CatalogSeeder first. Skipping UserSeeder.');

            return;
        }

        mt_srand(20_260_804);

        $playWeights = $this->playWeights($songs);
        $now = Carbon::now();
        $favorites = 0;
        $plays = 0;
        $playlists = 0;
        $tracks = 0;
        $searches = 0;

        foreach (self::USERS as [$name, $email, $country, $language]) {
            $user = $this->upsertUser($name, $email, $country, $language);
            $userId = (string) $user->getKey();

            $this->resetLibrary($userId);

            $favorites += $this->seedFavorites($userId, $songs, $now);
            $plays += $this->seedHistory($userId, $songs, $playWeights);
            $searches += $this->seedSearchHistory($userId);

            foreach (range(1, mt_rand(1, 3)) as $ignored) {
                $tracks += $this->seedPlaylist($user, $songs);
                $playlists++;
            }
        }

        $this->command?->info(sprintf(
            'Users seeded: %d (login demo@musicdiscovery.test / password). %d favorites, %d plays, %d playlists, %d playlist tracks, %d searches.',
            count(self::USERS),
            $favorites,
            $plays,
            $playlists,
            $tracks,
            $searches,
        ));
    }

    /**
     * Songs are read as plain rows: the seeder only needs three columns and
     * hydrating a few thousand models for that would be wasteful.
     *
     * @return list<array{id: string, duration: int, popularity: int}>
     */
    private function songPool(): array
    {
        if (! DB::table('songs')->whereNull('deleted_at')->exists()) {
            $this->call(CatalogSeeder::class);
        }

        return DB::table('songs')
            ->whereNull('deleted_at')
            ->select('id', 'duration', 'popularity')
            ->get()
            ->map(fn (object $song): array => [
                'id' => (string) $song->id,
                'duration' => (int) $song->duration,
                'popularity' => (int) $song->popularity,
            ])
            ->all();
    }

    /**
     * Indices into the song pool, repeated in proportion to popularity, so
     * drawing at random produces a realistically lopsided play distribution.
     *
     * @param  list<array{id: string, duration: int, popularity: int}>  $songs
     * @return list<int>
     */
    private function playWeights(array $songs): array
    {
        $weights = [];

        foreach ($songs as $index => $song) {
            foreach (range(0, intdiv($song['popularity'], 15)) as $ignored) {
                $weights[] = $index;
            }
        }

        return $weights;
    }

    private function upsertUser(string $name, string $email, string $country, string $language): User
    {
        /** @var User $user */
        $user = User::withTrashed()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                // The `hashed` cast takes care of hashing on assignment.
                'password' => 'password',
                'email_verified_at' => now(),
                'avatar' => 'https://picsum.photos/seed/'.Str::slug($name).'/200/200',
                'country' => $country,
                'language' => $language,
            ],
        );

        if ($user->trashed()) {
            $user->restore();
        }

        return $user;
    }

    /**
     * Playlist tracks are removed by the database's cascade on the playlist.
     */
    private function resetLibrary(string $userId): void
    {
        DB::table('history')->where('user_id', $userId)->delete();
        DB::table('search_history')->where('user_id', $userId)->delete();
        DB::table('favorites')->where('user_id', $userId)->delete();

        Playlist::withTrashed()->where('user_id', $userId)->forceDelete();
    }

    /**
     * @param  list<array{id: string, duration: int, popularity: int}>  $songs
     */
    private function seedFavorites(string $userId, array $songs, Carbon $now): int
    {
        $rows = [];

        // Distinct indices: (user_id, song_id) is unique.
        foreach ($this->distinctIndices($songs, mt_rand(12, 35)) as $index) {
            $addedAt = $now->copy()->subMinutes(mt_rand(0, 120 * 24 * 60));

            $rows[] = [
                'id' => Str::uuid7()->toString(),
                'user_id' => $userId,
                'song_id' => $songs[$index]['id'],
                'created_at' => $addedAt,
                'updated_at' => $addedAt,
            ];
        }

        $this->insertChunked('favorites', $rows);

        return count($rows);
    }

    /**
     * @param  list<array{id: string, duration: int, popularity: int}>  $songs
     * @param  list<int>  $playWeights
     */
    private function seedHistory(string $userId, array $songs, array $playWeights): int
    {
        $rows = [];
        $windowMinutes = self::HISTORY_WINDOW_DAYS * 24 * 60;
        $plays = mt_rand(60, 220);

        foreach (range(1, $plays) as $ignored) {
            $song = $songs[$playWeights[array_rand($playWeights)]];
            // Lowest of two draws: listening skews toward the last few days.
            $minutesAgo = min(mt_rand(0, $windowMinutes), mt_rand(0, $windowMinutes));

            $rows[] = [
                'id' => Str::uuid7()->toString(),
                'user_id' => $userId,
                'song_id' => $song['id'],
                'played_at' => Carbon::now()->subMinutes($minutesAgo),
                'ms_played' => $this->msPlayed($song['duration']),
            ];
        }

        // `history` has no timestamps: played_at is the only time column.
        $this->insertChunked('history', $rows);

        return count($rows);
    }

    private function seedSearchHistory(string $userId): int
    {
        $rows = [];

        foreach (range(1, mt_rand(4, 12)) as $ignored) {
            $rows[] = [
                'id' => Str::uuid7()->toString(),
                'user_id' => $userId,
                'keyword' => self::SEARCH_KEYWORDS[array_rand(self::SEARCH_KEYWORDS)],
                // Some queries return nothing, which the analytics view reports on.
                'results_count' => mt_rand(0, 10) === 0 ? 0 : mt_rand(1, 60),
                'searched_at' => Carbon::now()->subMinutes(mt_rand(0, self::HISTORY_WINDOW_DAYS * 24 * 60)),
            ];
        }

        $this->insertChunked('search_history', $rows);

        return count($rows);
    }

    /**
     * @param  list<array{id: string, duration: int, popularity: int}>  $songs
     * @return int the number of tracks added
     */
    private function seedPlaylist(User $user, array $songs): int
    {
        $title = self::PLAYLIST_TITLES[array_rand(self::PLAYLIST_TITLES)];
        $indices = $this->distinctIndices($songs, mt_rand(8, 25));

        /** @var Playlist $playlist */
        $playlist = Playlist::query()->create([
            'user_id' => $user->getKey(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.substr(Str::uuid7()->toString(), 0, 8),
            'description' => sprintf('%s — a development playlist for %s.', $title, $user->name),
            'cover_image' => 'https://picsum.photos/seed/'.Str::slug($title).'/600/600',
            'visibility' => $this->visibility(),
        ]);

        $rows = [];
        $position = 1;
        $totalDuration = 0;
        $addedAt = Carbon::now()->subDays(mt_rand(1, 60));

        foreach ($indices as $index) {
            $song = $songs[$index];
            $addedAt = $addedAt->copy()->addMinutes(mt_rand(1, 240));

            $rows[] = [
                'id' => Str::uuid7()->toString(),
                'playlist_id' => $playlist->getKey(),
                'song_id' => $song['id'],
                // Contiguous 1..N; the API renumbers on reorder.
                'position' => $position++,
                'added_at' => $addedAt->min(Carbon::now()),
            ];

            $totalDuration += $song['duration'];
        }

        $this->insertChunked('playlist_tracks', $rows);

        /*
         | tracks_count / total_duration are denormalized and not fillable, so
         | they are written here from the rows actually inserted rather than
         | guessed by the factory.
         */
        $playlist->forceFill([
            'tracks_count' => count($rows),
            'total_duration' => $totalDuration,
        ])->save();

        return count($rows);
    }

    private function visibility(): PlaylistVisibility
    {
        return match (mt_rand(1, 10)) {
            1, 2, 3, 4, 5, 6 => PlaylistVisibility::Public,
            7, 8 => PlaylistVisibility::Unlisted,
            default => PlaylistVisibility::Private,
        };
    }

    /**
     * Random, non-repeating positions in the song pool.
     *
     * @param  list<array{id: string, duration: int, popularity: int}>  $songs
     * @return list<int>
     */
    private function distinctIndices(array $songs, int $count): array
    {
        $count = min($count, count($songs));

        if ($count === 0) {
            return [];
        }

        $picked = array_rand($songs, $count);

        return is_array($picked) ? array_values($picked) : [$picked];
    }

    private function msPlayed(int $duration): ?int
    {
        return match (mt_rand(1, 10)) {
            // Client did not report progress.
            1 => null,
            // Skipped part-way through.
            2, 3 => mt_rand(15, max(16, $duration)) * 1_000,
            default => $duration * 1_000,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunked(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            // insertOrIgnore: the library tables are unique on (user, song) and
            // (playlist, song), and a re-run must not abort on a collision.
            DB::table($table)->insertOrIgnore($chunk);
        }
    }
}
