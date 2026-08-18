<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sync;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderSongData;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Language;
use App\Services\Catalog\SoundtrackParser;
use App\Services\Sync\MetadataNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MetadataNormalizer is the boundary where a provider's own vocabulary (ISO
 * 639-3 codes, inconsistent genre punctuation, bare artist names) gets folded
 * down to the handful of local lookup rows the catalog actually uses.
 */
class MetadataNormalizerTest extends TestCase
{
    use RefreshDatabase;

    private MetadataNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new MetadataNormalizer(new SoundtrackParser);
    }

    public function test_resolve_language_folds_a_2_letter_code_a_639_3_code_and_an_english_name_to_one_row(): void
    {
        $byCode = $this->normalizer->resolveLanguage('hi');
        $byIso6393 = $this->normalizer->resolveLanguage('hin');
        $byEnglishName = $this->normalizer->resolveLanguage('Hindi');

        $this->assertNotNull($byCode);
        $this->assertNotNull($byIso6393);
        $this->assertNotNull($byEnglishName);

        $this->assertSame($byCode->getKey(), $byIso6393->getKey());
        $this->assertSame($byCode->getKey(), $byEnglishName->getKey());
        $this->assertSame('hi', $byCode->code);

        // firstOrCreate must not have produced three rows for one language.
        $this->assertSame(1, Language::query()->where('code', 'hi')->count());
    }

    public function test_resolve_genre_folds_differently_punctuated_variants_to_one_row(): void
    {
        $hipHop = $this->normalizer->resolveGenre('Hip Hop');
        $hipHopHyphenated = $this->normalizer->resolveGenre('hip-hop');

        $this->assertNotNull($hipHop);
        $this->assertNotNull($hipHopHyphenated);
        $this->assertSame($hipHop->getKey(), $hipHopHyphenated->getKey());
        $this->assertSame('hip-hop', $hipHop->slug);

        // The first provider's casing wins; the second call must not have
        // overwritten it with 'Hip-hop'.
        $this->assertSame('Hip Hop', $hipHop->fresh()->name);
        $this->assertSame(1, Genre::query()->where('slug', 'hip-hop')->count());
    }

    public function test_resolve_artist_finds_or_creates_by_slug(): void
    {
        $first = $this->normalizer->resolveArtist('The Wanderers');
        $second = $this->normalizer->resolveArtist('the wanderers');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('the-wanderers', $first->slug);

        // The name captured on first creation must survive a later
        // differently-cased lookup.
        $this->assertSame('The Wanderers', $first->fresh()->name);
        $this->assertSame(1, Artist::query()->where('slug', 'the-wanderers')->count());
    }

    public function test_song_attributes_maps_fields_and_clamps_popularity_above_100(): void
    {
        $artist = Artist::factory()->create();
        $album = Album::factory()->forArtist($artist)->create();

        $data = new ProviderSongData(
            provider: 'spotify',
            externalId: 'sp-1',
            title: '  Midnight   Drive  ',
            artist: $artist->name,
            album: $album->title,
            duration: 215,
            genre: 'Hip Hop',
            language: 'Hindi',
            releaseDate: '2020-05-01',
            image: 'https://img.example.test/cover.jpg',
            popularity: 150,
            isrc: 'us-rc1-76-07839',
            previewUrl: 'https://preview.example.test/a.mp3',
            externalUrl: 'https://listen.example.test/a',
        );

        $attributes = $this->normalizer->songAttributes($data, $artist, $album);

        $this->assertSame($artist->getKey(), $attributes['artist_id']);
        $this->assertSame($album->getKey(), $attributes['album_id']);
        $this->assertSame(Genre::query()->where('slug', 'hip-hop')->first()->getKey(), $attributes['genre_id']);
        $this->assertSame(Language::query()->where('code', 'hi')->first()->getKey(), $attributes['language_id']);
        $this->assertSame('Midnight Drive', $attributes['title']);
        $this->assertSame('midnight-drive', $attributes['slug']);
        $this->assertSame(215, $attributes['duration']);
        $this->assertSame('USRC17607839', $attributes['isrc']);
        $this->assertSame('2020-05-01', $attributes['release_date']);
        // Popularity is unsignedTinyInteger: 150 must clamp down to 100.
        $this->assertSame(100, $attributes['popularity']);
        $this->assertSame('https://preview.example.test/a.mp3', $attributes['preview_url']);
        $this->assertSame('https://listen.example.test/a', $attributes['external_url']);
    }

    /**
     * The whole `songs.track_number` column was null in a real catalog of ~2,000
     * rows because the DTO carried no position and this method never wrote one.
     * Both halves are asserted here: a supplied position reaches the column, and
     * an absent one stays null rather than becoming 0 — `writeEntity()` drops
     * nulls, and that is what stops a search-sourced re-sync (which cannot know
     * the position) from wiping one an album tracklist had established.
     */
    public function test_song_attributes_maps_track_number_and_leaves_it_null_when_the_provider_gave_none(): void
    {
        $artist = Artist::factory()->create();

        $fromTracklist = $this->normalizer->songAttributes(
            new ProviderSongData(
                provider: 'jiosaavn',
                externalId: 'js-1',
                title: 'Tum Hi Ho',
                artist: $artist->name,
                trackNumber: 1,
            ),
            $artist,
            null,
        );

        $fromSearch = $this->normalizer->songAttributes(
            new ProviderSongData(
                provider: 'jiosaavn',
                externalId: 'js-2',
                title: 'Tum Hi Ho',
                artist: $artist->name,
            ),
            $artist,
            null,
        );

        $this->assertSame(1, $fromTracklist['track_number']);
        $this->assertArrayHasKey('track_number', $fromSearch);
        $this->assertNull($fromSearch['track_number']);
    }

    /**
     * The checksum short-circuit in SyncService returns *before* the row is
     * written, so a position that does not change the fingerprint can never be
     * persisted onto a song already synced from a search. That is precisely how
     * the column stayed empty, and it is a silent failure — the sync reports
     * success either way — so it is pinned here rather than left to inspection.
     */
    public function test_track_number_changes_the_checksum_so_a_tracklist_sync_is_not_skipped_as_unchanged(): void
    {
        $fields = [
            'provider' => 'jiosaavn',
            'externalId' => 'js-1',
            'title' => 'Tum Hi Ho',
            'artist' => 'Arijit Singh',
        ];

        $fromSearch = new ProviderSongData(...$fields);
        $fromTracklist = new ProviderSongData(...$fields, trackNumber: 1);
        $laterInTheAlbum = new ProviderSongData(...$fields, trackNumber: 7);

        $this->assertNotSame($fromSearch->checksum(), $fromTracklist->checksum());
        $this->assertNotSame($fromTracklist->checksum(), $laterInTheAlbum->checksum());
    }

    public function test_song_attributes_clamps_negative_popularity_to_zero_and_passes_null_duration_through(): void
    {
        $artist = Artist::factory()->create();

        $data = new ProviderSongData(
            provider: 'spotify',
            externalId: 'sp-2',
            title: 'No Duration Given',
            artist: $artist->name,
            duration: null,
            popularity: -5,
        );

        $attributes = $this->normalizer->songAttributes($data, $artist, null);

        $this->assertNull($attributes['album_id']);
        $this->assertNull($attributes['genre_id']);
        $this->assertNull($attributes['language_id']);
        /*
         | Passed through as null, NOT coerced to 0 here. SyncService::writeEntity()
         | is the layer that drops null attributes (on both create and update),
         | which is what lets the songs.duration column's DB-level default(0)
         | apply on create while never overwriting a real value on update.
         | Coercing to 0 in this method would defeat that on the update path —
         | see SyncServiceTest for the regression this exact ordering prevents.
         */
        $this->assertNull($attributes['duration']);
        $this->assertSame(0, $attributes['popularity']);
    }

    public function test_album_attributes_maps_fields_and_clamps_popularity(): void
    {
        $artist = Artist::factory()->create();

        $data = new ProviderAlbumData(
            provider: 'deezer',
            externalId: 'dz-1',
            title: '  Open   Roads  ',
            artist: $artist->name,
            language: 'hin',
            releaseDate: '2019-01-01',
            image: 'https://img.example.test/album.jpg',
            totalTracks: 12,
            popularity: 999,
        );

        $attributes = $this->normalizer->albumAttributes($data, $artist);

        $this->assertSame($artist->getKey(), $attributes['artist_id']);
        $this->assertSame(Language::query()->where('code', 'hi')->first()->getKey(), $attributes['language_id']);
        $this->assertSame('Open Roads', $attributes['title']);
        $this->assertSame('open-roads', $attributes['slug']);
        $this->assertSame('https://img.example.test/album.jpg', $attributes['cover_image']);
        $this->assertSame('2019-01-01', $attributes['release_date']);
        $this->assertSame(12, $attributes['total_tracks']);
        $this->assertSame(100, $attributes['popularity']);
    }
}
