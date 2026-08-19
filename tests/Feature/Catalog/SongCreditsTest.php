<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\DTO\CatalogQuery;
use App\DTO\Providers\ProviderArtistCredit;
use App\Enums\CreditRole;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Provider;
use App\Models\ProviderArtistMapping;
use App\Models\Song;
use App\Models\SongCredit;
use App\Repositories\EloquentArtistRepository;
use App\Repositories\EloquentSongRepository;
use App\Services\Sync\CreditWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `song_credits`: how it is written, what it guarantees, and what an artist's
 * song list is once it exists.
 *
 * The invariant asserted throughout is that the table is a complete *superset*
 * of `songs.artist_id`. It is what allows {@see Song::scopeCreditedTo()} to be a
 * single indexed lookup instead of an `OR` across two access paths, and a query
 * that fast is only correct while the invariant holds — so it is tested from
 * each direction that can break it: a plain factory write, a display artist
 * being reassigned, and a provider credit list replacing an earlier one.
 */
class SongCreditsTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): Provider
    {
        return Provider::query()->firstOrCreate(
            ['api_name' => 'jiosaavn'],
            ['name' => 'JioSaavn', 'enabled' => true],
        );
    }

    private function writer(): CreditWriter
    {
        return app(CreditWriter::class);
    }

    /*
    |---------------------------------------------------------------------------
    | The superset invariant
    |---------------------------------------------------------------------------
    */

    /**
     * A song written by a factory — no provider, no sync — still has its
     * display artist in the credits table.
     *
     * This is the case that decides whether a fresh database works at all. Every
     * artist page reads through the credits table now, so a song that never got
     * a credit row would be invisible on its own artist's page.
     */
    public function test_a_newly_created_song_is_credited_to_its_display_artist(): void
    {
        $song = Song::factory()->create();

        $this->assertDatabaseHas('song_credits', [
            'song_id' => $song->getKey(),
            'artist_id' => $song->artist_id,
            'role' => CreditRole::Primary->value,
        ]);
    }

    /** Saving a song repeatedly must not accumulate duplicate credit rows. */
    public function test_resaving_a_song_does_not_duplicate_its_display_artist_credit(): void
    {
        $song = Song::factory()->create();

        $song->touch();
        $song->update(['popularity' => 42]);

        $this->assertSame(1, SongCredit::query()
            ->where('song_id', $song->getKey())
            ->where('artist_id', $song->artist_id)
            ->where('role', CreditRole::Primary->value)
            ->count());
    }

    /**
     * Reassigning the display artist moves the credit, so the previous artist
     * stops claiming the song.
     */
    public function test_changing_the_display_artist_moves_the_primary_credit(): void
    {
        $song = Song::factory()->create();
        $before = (string) $song->artist_id;
        $after = Artist::factory()->create();

        $song->update(['artist_id' => $after->getKey()]);

        $this->assertDatabaseMissing('song_credits', [
            'song_id' => $song->getKey(),
            'artist_id' => $before,
            'role' => CreditRole::Primary->value,
        ]);
        $this->assertDatabaseHas('song_credits', [
            'song_id' => $song->getKey(),
            'artist_id' => $after->getKey(),
            'role' => CreditRole::Primary->value,
        ]);
    }

    /**
     * ...but not when the provider genuinely credits that artist too.
     *
     * There is no column marking which `primary` rows were seeded, so the only
     * available signal is whether the departing artist holds any other credit on
     * the song. Someone credited as the lyricist is really involved, and their
     * headline credit is not the label's to delete.
     */
    public function test_changing_the_display_artist_keeps_a_credit_the_provider_published(): void
    {
        $song = Song::factory()->create();
        $lyricist = (string) $song->artist_id;

        SongCredit::query()->create([
            'song_id' => $song->getKey(),
            'artist_id' => $lyricist,
            'role' => CreditRole::Lyricist->value,
            'position' => 0,
        ]);

        $song->update(['artist_id' => Artist::factory()->create()->getKey()]);

        $this->assertDatabaseHas('song_credits', [
            'song_id' => $song->getKey(),
            'artist_id' => $lyricist,
            'role' => CreditRole::Primary->value,
        ]);
    }

    /*
    |---------------------------------------------------------------------------
    | CreditWriter
    |---------------------------------------------------------------------------
    */

    /** A credit whose provider ID is already mapped resolves to that artist. */
    public function test_a_credit_resolves_through_the_provider_mapping_before_the_name(): void
    {
        $provider = $this->provider();
        $song = Song::factory()->create();

        // Same person, two spellings. The ID is what knows they are the same.
        $mapped = Artist::factory()->create(['name' => 'A. R. Rahman']);
        ProviderArtistMapping::query()->create([
            'artist_id' => $mapped->getKey(),
            'provider_id' => $provider->getKey(),
            'provider_artist_id' => '99001',
        ]);

        $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('99001', CreditRole::Composer, 'AR Rahman'),
        ]);

        $this->assertDatabaseHas('song_credits', [
            'song_id' => $song->getKey(),
            'artist_id' => $mapped->getKey(),
            'role' => CreditRole::Composer->value,
        ]);
        // The differently-spelled name must not have created a second artist.
        $this->assertSame(0, Artist::query()->where('name', 'AR Rahman')->count());
    }

    /**
     * An unmapped credit creates the artist and records the provider ID, so the
     * next payload naming them resolves by ID instead of by name.
     */
    public function test_an_unmapped_credit_creates_the_artist_and_its_mapping(): void
    {
        $provider = $this->provider();
        $song = Song::factory()->create();

        $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('99002', CreditRole::Lyricist, 'Brand New Lyricist'),
        ]);

        $artist = Artist::query()->where('name', 'Brand New Lyricist')->first();

        $this->assertNotNull($artist);
        $this->assertDatabaseHas('provider_artist_mappings', [
            'provider_id' => $provider->getKey(),
            'provider_artist_id' => '99002',
            'artist_id' => $artist->getKey(),
            // Nothing has been synced from the artist's own payload, so a
            // checksum here would make the next artist sync short-circuit.
            'checksum' => null,
        ]);
    }

    /**
     * A credit with no usable name and no mapping is skipped rather than guessed.
     *
     * This is the adapter's credit-line rejection arriving downstream: the name
     * was thrown away because it was really "A|B", and the ID has not been
     * crawled yet.
     */
    public function test_a_credit_with_no_name_and_no_mapping_is_skipped(): void
    {
        $provider = $this->provider();
        $song = Song::factory()->create();

        $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('99003', CreditRole::Singer, null),
        ]);

        $this->assertSame(0, SongCredit::query()
            ->where('song_id', $song->getKey())
            ->where('role', CreditRole::Singer->value)
            ->count());
    }

    /**
     * Two provider IDs resolving to one local artist in the same role collapse
     * instead of violating the table's unique key.
     *
     * The catalog deliberately merges duplicate provider entries for one person,
     * so this is the normal case rather than an edge one — and an unhandled
     * duplicate would abort the transaction and lose the whole song's credits.
     */
    public function test_two_provider_ids_for_one_artist_in_one_role_do_not_collide(): void
    {
        $provider = $this->provider();
        $song = Song::factory()->create();

        $count = $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('99004', CreditRole::Singer, 'Same Person'),
            new ProviderArtistCredit('99005', CreditRole::Singer, 'Same Person'),
        ]);

        $this->assertGreaterThan(0, $count);
        $this->assertSame(1, SongCredit::query()
            ->where('song_id', $song->getKey())
            ->where('role', CreditRole::Singer->value)
            ->count());
    }

    /** A non-empty credit list replaces the stored set, so a retraction lands. */
    public function test_writing_credits_replaces_the_previous_set(): void
    {
        $provider = $this->provider();
        $song = Song::factory()->create();

        $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('99006', CreditRole::Singer, 'Wrongly Credited'),
        ]);
        $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('99007', CreditRole::Singer, 'Correctly Credited'),
        ]);

        $names = SongCredit::query()
            ->where('song_id', $song->getKey())
            ->where('role', CreditRole::Singer->value)
            ->with('artist')
            ->get()
            ->map(fn (SongCredit $c): string => (string) $c->artist->name)
            ->all();

        $this->assertSame(['Correctly Credited'], $names);
    }

    /**
     * Replacing the credit list must not drop the display artist.
     *
     * The writer deletes everything for the song before inserting, so a payload
     * that happens not to credit the display artist would otherwise take the
     * song off its own artist's page — and the artist query has no `artist_id`
     * fallback to save it.
     */
    public function test_replacing_credits_keeps_the_display_artist(): void
    {
        $provider = $this->provider();
        $song = Song::factory()->create();

        $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('99008', CreditRole::Lyricist, 'Only A Lyricist'),
        ]);

        $this->assertDatabaseHas('song_credits', [
            'song_id' => $song->getKey(),
            'artist_id' => $song->artist_id,
            'role' => CreditRole::Primary->value,
        ]);
    }

    /** An empty list is a provider saying nothing, not a provider saying "none". */
    public function test_an_empty_credit_list_leaves_stored_credits_alone(): void
    {
        $provider = $this->provider();
        $song = Song::factory()->create();

        $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('99009', CreditRole::Singer, 'Established Singer'),
        ]);

        $before = SongCredit::query()->where('song_id', $song->getKey())->count();

        $this->assertSame(0, $this->writer()->write($song, $provider, []));
        $this->assertSame($before, SongCredit::query()->where('song_id', $song->getKey())->count());
    }

    /*
    |---------------------------------------------------------------------------
    | The discography query
    |---------------------------------------------------------------------------
    */

    /**
     * The fix, stated plainly: a composer's song list includes what they
     * composed, not only what they are labelled with.
     */
    public function test_an_artist_song_list_includes_songs_they_are_only_credited_on(): void
    {
        $provider = $this->provider();
        $composer = Artist::factory()->create(['name' => 'The Composer']);

        ProviderArtistMapping::query()->create([
            'artist_id' => $composer->getKey(),
            'provider_id' => $provider->getKey(),
            'provider_artist_id' => '77001',
        ]);

        $labelled = Song::factory()->create(['artist_id' => $composer->getKey()]);
        $creditedOnly = Song::factory()->create();

        $this->writer()->write($creditedOnly, $provider, [
            new ProviderArtistCredit('77001', CreditRole::Composer, 'The Composer'),
        ]);

        $found = app(EloquentSongRepository::class)
            ->forArtist((string) $composer->getKey(), new CatalogQuery(limit: 50))
            ->pluck('id')
            ->all();

        $this->assertContains($labelled->getKey(), $found);
        $this->assertContains($creditedOnly->getKey(), $found);
    }

    /**
     * A film actor credited on a soundtrack does not get someone else's songs.
     *
     * `starring` is stored — a credits block should show it — but it is not a
     * reason to put a track on a person's page.
     */
    public function test_an_actor_credit_does_not_put_a_song_on_their_page(): void
    {
        $provider = $this->provider();
        $actor = Artist::factory()->create(['name' => 'The Lead Actor']);

        ProviderArtistMapping::query()->create([
            'artist_id' => $actor->getKey(),
            'provider_id' => $provider->getKey(),
            'provider_artist_id' => '77002',
        ]);

        $soundtrackCut = Song::factory()->create();

        $this->writer()->write($soundtrackCut, $provider, [
            new ProviderArtistCredit('77002', CreditRole::Actor, 'The Lead Actor'),
        ]);

        // Stored ...
        $this->assertDatabaseHas('song_credits', [
            'song_id' => $soundtrackCut->getKey(),
            'artist_id' => $actor->getKey(),
            'role' => CreditRole::Actor->value,
        ]);
        // ... but not a discography entry.
        $this->assertSame(0, Song::query()->creditedTo((string) $actor->getKey())->count());
    }

    /**
     * A song credited twice to one artist appears once.
     *
     * A join would return it per matching credit and break the paginator's total;
     * EXISTS is what keeps the row count honest.
     */
    public function test_a_song_credited_in_two_roles_is_listed_once(): void
    {
        $provider = $this->provider();
        $artist = Artist::factory()->create(['name' => 'Sings And Composes']);

        ProviderArtistMapping::query()->create([
            'artist_id' => $artist->getKey(),
            'provider_id' => $provider->getKey(),
            'provider_artist_id' => '77003',
        ]);

        $song = Song::factory()->create();

        $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('77003', CreditRole::Singer, 'Sings And Composes'),
            new ProviderArtistCredit('77003', CreditRole::Composer, 'Sings And Composes'),
        ]);

        $this->assertSame(2, SongCredit::query()
            ->where('song_id', $song->getKey())
            ->where('artist_id', $artist->getKey())
            ->count());

        $paginator = app(EloquentSongRepository::class)
            ->forArtist((string) $artist->getKey(), new CatalogQuery(limit: 50));

        $this->assertSame(1, $paginator->total());
        $this->assertCount(1, $paginator->items());
    }

    /** The Popular shelf and the paginated list answer the same question. */
    public function test_the_popular_shelf_and_the_song_list_agree(): void
    {
        $provider = $this->provider();
        $artist = Artist::factory()->create(['name' => 'Prolific Composer']);

        ProviderArtistMapping::query()->create([
            'artist_id' => $artist->getKey(),
            'provider_id' => $provider->getKey(),
            'provider_artist_id' => '77004',
        ]);

        foreach (Song::factory()->count(3)->create() as $song) {
            $this->writer()->write($song, $provider, [
                new ProviderArtistCredit('77004', CreditRole::Composer, 'Prolific Composer'),
            ]);
        }

        $listed = app(EloquentSongRepository::class)
            ->forArtist((string) $artist->getKey(), new CatalogQuery(limit: 50))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $shelved = app(EloquentArtistRepository::class)
            ->popularSongs($artist, 50)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($listed, $shelved);
        $this->assertCount(3, $listed);
    }

    /*
    |---------------------------------------------------------------------------
    | The API surface
    |---------------------------------------------------------------------------
    */

    /** A song's own endpoint publishes the credits with roles. */
    public function test_the_song_endpoint_returns_credits_with_roles(): void
    {
        $provider = $this->provider();
        $song = Song::factory()->for(Album::factory())->create();

        $this->writer()->write($song, $provider, [
            new ProviderArtistCredit('66001', CreditRole::Singer, 'The Voice'),
            new ProviderArtistCredit('66002', CreditRole::Lyricist, 'The Pen'),
        ]);

        $response = $this->getJson("/api/v1/songs/{$song->getKey()}")->assertOk();

        $credits = collect($response->json('data.credits'));

        $this->assertTrue($credits->contains(
            fn (array $c): bool => $c['role'] === 'singer' && $c['artist']['name'] === 'The Voice',
        ));
        $this->assertTrue($credits->contains(
            fn (array $c): bool => $c['role'] === 'lyricist'
                && $c['role_label'] === 'Lyricist'
                && $c['artist']['name'] === 'The Pen',
        ));
    }

    /**
     * Listing endpoints do not carry credits, and must not query for them.
     *
     * A tracklist serialises up to fifty songs and shows no credits block, so
     * loading them there would be hundreds of rows fetched to be discarded.
     */
    public function test_listing_endpoints_omit_credits_entirely(): void
    {
        Song::factory()->count(3)->create();

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'song_credits')) {
                $queries++;
            }
        });

        $response = $this->getJson('/api/v1/songs?limit=3')->assertOk();

        $this->assertArrayNotHasKey('credits', $response->json('data.0'));
        $this->assertSame(0, $queries, 'a listing must not touch song_credits');
    }
}
