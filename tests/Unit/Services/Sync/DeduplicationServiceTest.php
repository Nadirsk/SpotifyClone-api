<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sync;

use App\DTO\Providers\ProviderSongData;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Provider;
use App\Models\ProviderSongMapping;
use App\Models\Song;
use App\Services\Sync\DeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the 4-step priority order DeduplicationService::findSong() promises
 * (07_SYNC_ENGINE §6): ISRC, then provider mapping, then title+artist+album,
 * then duration similarity. Each step is deliberately weaker and more willing
 * to produce a false match than the one above it, so the order itself is the
 * behaviour under test, not just each step in isolation.
 */
class DeduplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeduplicationService $deduplicator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deduplicator = new DeduplicationService;

        // Tests pin their own tolerance rather than trusting whatever
        // config/providers.php happens to contain.
        config(['providers.dedupe.duration_tolerance_seconds' => 3]);
    }

    public function test_isrc_match_wins_across_different_providers_and_titles(): void
    {
        $song = Song::factory()->create([
            'isrc' => 'USRC17607839',
        ]);

        $provider = Provider::factory()->create();

        // Same recording code, differently punctuated, but an entirely
        // different title and artist string from a second provider.
        $data = new ProviderSongData(
            provider: 'musicbrainz',
            externalId: 'mb-external-1',
            title: 'A Totally Different Title',
            artist: 'Some Other Billing',
            isrc: 'US-RC1-76-07839',
        );

        $result = $this->deduplicator->findSong($data, $provider);

        $this->assertNotNull($result);
        $this->assertTrue($result->is($song));
    }

    public function test_provider_mapping_match_survives_a_changed_title(): void
    {
        $provider = Provider::factory()->create();
        $song = Song::factory()->create();

        ProviderSongMapping::query()->create([
            'song_id' => $song->getKey(),
            'provider_id' => $provider->getKey(),
            'provider_song_id' => 'spotify-track-123',
            'checksum' => 'irrelevant-for-this-test',
            'last_synced_at' => now(),
        ]);

        // Same provider, same external id, but the provider now reports a
        // different title (e.g. a remaster rename) and no ISRC at all.
        $data = new ProviderSongData(
            provider: 'spotify',
            externalId: 'spotify-track-123',
            title: 'A Brand New Title (Remastered)',
            artist: 'Whoever',
            isrc: null,
        );

        $result = $this->deduplicator->findSong($data, $provider);

        $this->assertNotNull($result);
        $this->assertTrue($result->is($song));
    }

    public function test_title_artist_album_match_with_no_isrc_or_mapping(): void
    {
        $artist = Artist::factory()->create(['name' => 'The Wanderers', 'slug' => 'the-wanderers']);
        $album = Album::factory()->forArtist($artist)->create([
            'title' => 'Open Roads',
            'slug' => 'open-roads',
        ]);
        $song = Song::factory()->create([
            'artist_id' => $artist->getKey(),
            'album_id' => $album->getKey(),
            'title' => 'Midnight Drive',
            'slug' => 'midnight-drive',
            'isrc' => null,
        ]);

        $provider = Provider::factory()->create();

        // A second provider describing the same record in words: no ISRC,
        // no prior mapping for this provider/external id pair.
        $data = new ProviderSongData(
            provider: 'deezer',
            externalId: 'deezer-999',
            title: 'Midnight Drive',
            artist: 'The Wanderers',
            album: 'Open Roads',
            isrc: null,
        );

        $result = $this->deduplicator->findSong($data, $provider, $artist, $album);

        $this->assertNotNull($result);
        $this->assertTrue($result->is($song));
    }

    public function test_duration_within_tolerance_matches_when_album_disagrees(): void
    {
        $artist = Artist::factory()->create(['name' => 'The Wanderers', 'slug' => 'the-wanderers']);
        $song = Song::factory()->single()->create([
            'artist_id' => $artist->getKey(),
            'title' => 'Midnight Drive',
            'slug' => 'midnight-drive',
            // Kept >= the incoming duration deliberately: `duration` is an
            // unsignedInteger column, and DeduplicationService orders matches
            // with `orderByRaw('ABS(duration - ?)', ...)`. MySQL evaluates
            // that subtraction in unsigned arithmetic before ABS() ever runs,
            // so a candidate whose stored duration is LESS than the
            // incoming value throws "BIGINT UNSIGNED value is out of range"
            // (error 1690) instead of matching — a real bug, reproduced
            // separately and reported rather than fixed here.
            'duration' => 202,
            'isrc' => null,
        ]);

        $provider = Provider::factory()->create();

        // The album name the provider gives us does not match any album this
        // song is attached to (it has none), which knocks out step 3 and
        // forces the fall-through to duration similarity. 200s is within the
        // 3s tolerance of the existing 202s row.
        $data = new ProviderSongData(
            provider: 'lastfm',
            externalId: 'lastfm-1',
            title: 'Midnight Drive',
            artist: 'The Wanderers',
            album: 'Some Compilation That Does Not Exist',
            duration: 200,
            isrc: null,
        );

        $result = $this->deduplicator->findSong($data, $provider, $artist, null);

        $this->assertNotNull($result);
        $this->assertTrue($result->is($song));
    }

    public function test_duration_outside_tolerance_does_not_match(): void
    {
        $artist = Artist::factory()->create(['name' => 'The Wanderers', 'slug' => 'the-wanderers']);
        Song::factory()->single()->create([
            'artist_id' => $artist->getKey(),
            'title' => 'Midnight Drive',
            'slug' => 'midnight-drive',
            'duration' => 200,
            'isrc' => null,
        ]);

        $provider = Provider::factory()->create();

        // 210s is 10s away from the existing 200s row — outside the 3s
        // tolerance, so this must be treated as a new, unrelated recording.
        $data = new ProviderSongData(
            provider: 'lastfm',
            externalId: 'lastfm-2',
            title: 'Midnight Drive',
            artist: 'The Wanderers',
            album: 'Some Compilation That Does Not Exist',
            duration: 210,
            isrc: null,
        );

        $result = $this->deduplicator->findSong($data, $provider, $artist, null);

        $this->assertNull($result);
    }

    public function test_priority_order_isrc_wins_over_a_conflicting_title_artist_match(): void
    {
        $provider = Provider::factory()->create();

        // Song X is the ISRC match: right code, but a title and artist that
        // share nothing with the incoming record.
        $songX = Song::factory()->create([
            'isrc' => 'USABC0000001',
            'title' => 'Completely Unrelated Track',
            'slug' => 'completely-unrelated-track',
        ]);

        // Song Y is what title+artist alone WOULD resolve to, if the ISRC
        // step were skipped: same title and same (resolved) artist as the
        // incoming data, but a different ISRC.
        $artistY = Artist::factory()->create(['name' => 'Shared Artist', 'slug' => 'shared-artist']);
        $songY = Song::factory()->create([
            'artist_id' => $artistY->getKey(),
            'title' => 'Shared Title',
            'slug' => 'shared-title',
            'isrc' => 'USXYZ9999999',
        ]);

        $data = new ProviderSongData(
            provider: 'spotify',
            externalId: 'priority-check-1',
            title: 'Shared Title',
            artist: 'Shared Artist',
            isrc: 'US-ABC-00-00001',
        );

        $result = $this->deduplicator->findSong($data, $provider, $artistY, null);

        $this->assertNotNull($result);
        $this->assertTrue($result->is($songX), 'ISRC must win even though title+artist would have matched a different song.');
        $this->assertFalse($result->is($songY));
    }
}
