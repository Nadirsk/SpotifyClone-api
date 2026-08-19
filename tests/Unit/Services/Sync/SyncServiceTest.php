<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sync;

use App\DTO\Providers\ProviderSongData;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Provider;
use App\Models\ProviderSongMapping;
use App\Models\Song;
use App\Services\Catalog\SoundtrackParser;
use App\Services\Sync\CreditWriter;
use App\Services\Sync\DeduplicationService;
use App\Services\Sync\MetadataNormalizer;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Pins the two load-bearing behaviours the SyncService docblock calls out
 * (07_SYNC_ENGINE §3, §11): the checksum short-circuit that skips a write
 * when nothing changed, and the "nulls never overwrite" merge that lets
 * several providers enrich one record instead of fighting over it. Also
 * covers the validation gate (§12) that decides whether syncSong() ever
 * reaches persistence at all.
 */
class SyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private SyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();

        $normalizer = new MetadataNormalizer(new SoundtrackParser);

        $this->syncService = new SyncService(
            $normalizer,
            new DeduplicationService,
            new CreditWriter($normalizer),
            app(LoggerInterface::class),
        );

        // Pin the validation range independently of config/providers.php.
        config([
            'providers.sync.min_duration' => 1,
            'providers.sync.max_duration' => 21_600,
        ]);
    }

    /**
     * Builds a ProviderSongData with sane defaults, overridable per test.
     *
     * `popularity` defaults to a real value rather than null: MetadataNormalizer
     * only nulls it out when a provider truly omits it, and the `popularity`
     * column has no nullable() in the songs migration, so a first-ever sync
     * (the create() path) needs a concrete value to insert. Tests that care
     * about null-handling for popularity specifically override it themselves.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function songData(array $overrides = []): ProviderSongData
    {
        $defaults = [
            'provider' => 'spotify',
            'externalId' => 'ext-'.Str::random(12),
            'title' => 'Sample Title',
            'artist' => 'Sample Artist',
            'album' => null,
            'duration' => 200,
            'genre' => null,
            'language' => null,
            'releaseDate' => null,
            'image' => null,
            'popularity' => 50,
            'isrc' => null,
            'previewUrl' => null,
            'externalUrl' => null,
        ];

        return new ProviderSongData(...array_merge($defaults, $overrides));
    }

    public function test_sync_song_creates_a_new_song_and_a_provider_mapping_on_first_sync(): void
    {
        $provider = Provider::factory()->create();
        $data = $this->songData([
            'title' => 'Original Title',
            'artist' => 'Original Artist',
            'duration' => 210,
        ]);

        $song = $this->syncService->syncSong($provider, $data);

        $this->assertNotNull($song);
        $this->assertSame(1, Song::query()->count());
        $this->assertSame(1, ProviderSongMapping::query()->count());

        $mapping = ProviderSongMapping::query()->first();
        $this->assertSame($song->getKey(), $mapping->song_id);
        $this->assertSame($provider->getKey(), $mapping->provider_id);
        $this->assertSame($data->externalId, $mapping->provider_song_id);
        $this->assertSame($data->checksum(), $mapping->checksum);

        $fresh = $song->fresh(['artist']);
        $this->assertSame('Original Title', $fresh->title);
        $this->assertSame(210, $fresh->duration);
        $this->assertSame('Original Artist', $fresh->artist->name);
    }

    public function test_resyncing_unchanged_data_skips_the_song_write_but_bumps_mapping_freshness(): void
    {
        $provider = Provider::factory()->create();
        $data = $this->songData(['title' => 'Steady Title']);

        $song = $this->syncService->syncSong($provider, $data);
        $this->assertNotNull($song);

        // Push both rows' freshness markers artificially into the past so the
        // assertions below cannot pass by coincidence of timestamp precision.
        $stale = now()->subDays(2)->startOfSecond();
        DB::table('songs')->where('id', $song->getKey())->update(['updated_at' => $stale]);
        DB::table('provider_song_mappings')->where('song_id', $song->getKey())->update(['last_synced_at' => $stale]);

        // Same provider, identical payload => identical checksum.
        $resynced = $this->syncService->syncSong($provider, $data);

        $this->assertNotNull($resynced);
        $this->assertTrue($resynced->is($song));
        $this->assertSame(1, Song::query()->count());
        $this->assertSame(1, ProviderSongMapping::query()->count());

        // The entity itself was never touched: skipUnchanged() returns the
        // relation as loaded, without saving the song model at all.
        $songRow = Song::query()->find($song->getKey());
        $this->assertTrue($songRow->updated_at->equalTo($stale));

        // Only the mapping's freshness marker moved.
        $mapping = ProviderSongMapping::query()->where('song_id', $song->getKey())->first();
        $this->assertTrue($mapping->last_synced_at->greaterThan($stale));
    }

    public function test_resyncing_with_a_different_title_updates_the_song(): void
    {
        $provider = Provider::factory()->create();
        $data = $this->songData(['title' => 'Original Title', 'artist' => 'Steady Artist']);

        $song = $this->syncService->syncSong($provider, $data);
        $this->assertNotNull($song);

        $changed = $this->songData([
            'externalId' => $data->externalId,
            'title' => 'Updated Title',
            'artist' => 'Steady Artist',
        ]);

        $updated = $this->syncService->syncSong($provider, $changed);

        $this->assertNotNull($updated);
        $this->assertTrue($updated->is($song));
        $this->assertSame(1, Song::query()->count());
        $this->assertSame(1, ProviderSongMapping::query()->count());

        $fresh = Song::query()->find($song->getKey());
        $this->assertSame('Updated Title', $fresh->title);
        $this->assertSame('updated-title', $fresh->slug);

        $mapping = ProviderSongMapping::query()->where('song_id', $song->getKey())->first();
        $this->assertSame($changed->checksum(), $mapping->checksum);
    }

    public function test_enrichment_from_a_second_provider_never_erases_fields_the_first_provider_set(): void
    {
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->create();
        $isrc = 'USABC0000099';

        // Provider A supplies genre and language; it knows nothing about a
        // preview URL.
        $dataA = $this->songData([
            'externalId' => 'a-ext-1',
            'title' => 'Shared Song',
            'artist' => 'Shared Artist',
            'genre' => 'Rock',
            'language' => 'English',
            'duration' => 200,
            'isrc' => $isrc,
            'previewUrl' => null,
        ]);

        $songFromA = $this->syncService->syncSong($providerA, $dataA);
        $this->assertNotNull($songFromA);

        $rockId = Genre::query()->where('slug', 'rock')->first()?->getKey();
        $englishId = Language::query()->where('code', 'en')->first()?->getKey();
        $this->assertNotNull($rockId);
        $this->assertNotNull($englishId);
        $this->assertSame($rockId, $songFromA->fresh()->genre_id);
        $this->assertSame($englishId, $songFromA->fresh()->language_id);

        // Provider B matches the same recording via ISRC (punctuated
        // differently) and knows only a preview URL — genre, language and
        // popularity are all left null, i.e. "did not say".
        $dataB = $this->songData([
            'externalId' => 'b-ext-1',
            'title' => 'Shared Song',
            'artist' => 'Shared Artist',
            'genre' => null,
            'language' => null,
            'duration' => 200,
            'isrc' => 'US-ABC-00-00099',
            'previewUrl' => 'https://preview.example.test/from-b.mp3',
            'popularity' => null,
        ]);

        $songFromB = $this->syncService->syncSong($providerB, $dataB);

        $this->assertNotNull($songFromB);
        $this->assertTrue($songFromB->is($songFromA), 'Provider B must resolve to the same song via ISRC.');
        $this->assertSame(1, Song::query()->count(), 'Enrichment must not create a second row.');
        $this->assertSame(2, ProviderSongMapping::query()->count(), 'Each provider gets its own mapping row.');

        $merged = Song::query()->find($songFromA->getKey());

        // A's contributions survive B's sparse payload.
        $this->assertSame($rockId, $merged->genre_id, 'Genre set by provider A must survive provider B leaving it null.');
        $this->assertSame($englishId, $merged->language_id, 'Language set by provider A must survive provider B leaving it null.');
        $this->assertSame(50, $merged->popularity, 'Popularity set by provider A must survive provider B leaving it null.');

        // B's own contribution is applied.
        $this->assertSame('https://preview.example.test/from-b.mp3', $merged->preview_url);
    }

    /**
     * duration participates in "nulls never overwrite" exactly like genre,
     * language and popularity: MetadataNormalizer::songAttributes() passes an
     * unknown duration through as null rather than coercing it to 0, and
     * SyncService::writeEntity() drops null attributes on both create and
     * update. A second, sparser provider therefore enriches the record
     * without erasing the duration a richer provider already supplied.
     */
    public function test_null_duration_from_an_enriching_provider_does_not_erase_an_existing_duration(): void
    {
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->create();
        $isrc = 'USABC0000077';

        $dataA = $this->songData([
            'externalId' => 'dur-a-1',
            'title' => 'Duration Test Song',
            'artist' => 'Duration Artist',
            'duration' => 240,
            'isrc' => $isrc,
        ]);
        $song = $this->syncService->syncSong($providerA, $dataA);
        $this->assertSame(240, $song->fresh()->duration);

        $dataB = $this->songData([
            'externalId' => 'dur-b-1',
            'title' => 'Duration Test Song',
            'artist' => 'Duration Artist',
            'duration' => null, // provider B "did not say"
            'isrc' => 'US-ABC-00-00077',
        ]);
        $this->syncService->syncSong($providerB, $dataB);

        $this->assertSame(
            240,
            $song->fresh()->duration,
            'Duration set by provider A must survive provider B leaving it null.',
        );
    }

    /**
     * The other half of the same guarantee: when NO provider has ever
     * supplied a duration, the songs.duration column's own migration-level
     * default(0) is what a brand-new row gets — writeEntity() must omit the
     * key entirely rather than pass an explicit null into create().
     */
    public function test_first_sync_with_no_duration_falls_back_to_the_column_default(): void
    {
        $provider = Provider::factory()->create();

        $data = $this->songData([
            'externalId' => 'dur-c-1',
            'title' => 'Unknown Duration Song',
            'artist' => 'Duration Artist',
            'duration' => null,
        ]);

        $song = $this->syncService->syncSong($provider, $data);

        $this->assertNotNull($song);
        $this->assertSame(0, $song->fresh()->duration);
    }

    public function test_song_with_empty_title_is_rejected_and_nothing_is_written(): void
    {
        $provider = Provider::factory()->create();
        $data = $this->songData(['title' => '   ']);

        $result = $this->syncService->syncSong($provider, $data);

        $this->assertNull($result);
        $this->assertSame(0, Song::query()->count());
        $this->assertSame(0, ProviderSongMapping::query()->count());
    }

    public function test_duration_of_zero_from_the_provider_not_saying_is_not_rejected(): void
    {
        $provider = Provider::factory()->create();
        // A null duration means the provider did not say (Last.fm's search
        // endpoint never does) and validation must let it through, storing 0.
        $data = $this->songData(['duration' => null]);

        $song = $this->syncService->syncSong($provider, $data);

        $this->assertNotNull($song);
        $this->assertSame(0, $song->fresh()->duration);
    }

    public function test_duration_far_beyond_max_duration_is_rejected(): void
    {
        $provider = Provider::factory()->create();
        $data = $this->songData(['duration' => 100_000]);

        $result = $this->syncService->syncSong($provider, $data);

        $this->assertNull($result);
        $this->assertSame(0, Song::query()->count());
        $this->assertSame(0, ProviderSongMapping::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Album membership stability
    |--------------------------------------------------------------------------
    |
    | An album's tracklist has to be the same tracklist on the next request.
    | It was not: `album_id` was rewritten from the incoming payload's album
    | *name* on every sync, and a search result names a release differently
    | from the tracklist a song actually sits on — so a song moved between
    | albums depending on which crawl path touched it last, and albums gained
    | and lost tracks while being browsed.
    |
    | `trackNumber` is the authority marker: only `JioSaavnAdapter::albumTracks()`
    | supplies one. See SyncService::withStableAlbumMembership().
    */

    public function test_a_search_sourced_sync_cannot_move_a_song_off_the_album_a_tracklist_put_it_on(): void
    {
        $provider = Provider::factory()->create();
        $externalId = 'ext-stable-membership';

        // Reached through the album's own tracklist: position known, so this is
        // the authoritative statement of where the song lives.
        $song = $this->syncService->syncSong($provider, $this->songData([
            'externalId' => $externalId,
            'title' => 'Shararat',
            'artist' => 'Madhubanti Bagchi',
            'album' => 'Dhurandhar',
            'trackNumber' => 7,
        ]));

        $this->assertNotNull($song);
        $albumId = $song->fresh()->album_id;
        $this->assertNotNull($albumId);

        // The same song turning up in a search, where the provider labels it
        // with its single release instead. No position, so no authority.
        $this->syncService->syncSong($provider, $this->songData([
            'externalId' => $externalId,
            'title' => 'Shararat',
            'artist' => 'Madhubanti Bagchi',
            'album' => 'Shararat (From "Dhurandhar")',
            'trackNumber' => null,
            // Something has to differ or the checksum short-circuit skips the write.
            'popularity' => 71,
        ]));

        $fresh = $song->fresh();
        $this->assertSame($albumId, $fresh->album_id, 'a search result must not move a song off its album');
        $this->assertSame(7, $fresh->track_number, 'nor discard the position the tracklist established');
        $this->assertSame(71, $fresh->popularity, 'while everything else it does know still merges');
    }

    public function test_an_authoritative_move_reassigns_the_album_and_clears_the_stale_position(): void
    {
        $provider = Provider::factory()->create();
        $externalId = 'ext-authoritative-move';

        $song = $this->syncService->syncSong($provider, $this->songData([
            'externalId' => $externalId,
            'album' => 'First Album',
            'trackNumber' => 3,
        ]));

        $this->assertNotNull($song);
        $first = $song->fresh()->album_id;

        // Another album's tracklist claims it, at a different position. This one
        // does have standing, so the move goes through.
        $this->syncService->syncSong($provider, $this->songData([
            'externalId' => $externalId,
            'album' => 'Second Album',
            'trackNumber' => 9,
        ]));

        $fresh = $song->fresh();
        $this->assertNotSame($first, $fresh->album_id);
        $this->assertSame(9, $fresh->track_number, 'the new album'.'"'.'s position, not the old one');
    }

    public function test_a_search_sourced_sync_still_attaches_a_song_that_has_no_album_yet(): void
    {
        $provider = Provider::factory()->create();
        $externalId = 'ext-first-attachment';

        $song = $this->syncService->syncSong($provider, $this->songData([
            'externalId' => $externalId,
            'album' => null,
        ]));

        $this->assertNotNull($song);
        $this->assertNull($song->fresh()->album_id);

        // Nothing is being overwritten, so a search result is welcome to supply
        // the first album this song has ever had.
        $this->syncService->syncSong($provider, $this->songData([
            'externalId' => $externalId,
            'album' => 'Discovered By Search',
            'trackNumber' => null,
        ]));

        $this->assertNotNull($song->fresh()->album_id);
    }
}
