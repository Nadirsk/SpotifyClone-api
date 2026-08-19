<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Providers;

use App\DTO\Providers\ProviderArtistCredit;
use App\Enums\CreditRole;
use App\Services\Providers\JioSaavn\JioSaavnAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * How the adapter reads a song's credit list, and who it elects as the display
 * artist once it can see the roles.
 *
 * Every payload below is the real shape returned by the wrapper for the named
 * track, trimmed to the fields under test. The awkward ones are the point: the
 * union of three differently-shaped arrays, a headline singer who appears in
 * only one of them, and a track where the provider names no singer at all.
 */
class JioSaavnCreditsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        config([
            'providers.jiosaavn.enabled' => true,
            'providers.jiosaavn.base_url' => 'https://provider.test/api',
            'providers.jiosaavn.rate_limit.requests' => 1_000,
            'providers.jiosaavn.rate_limit.per_seconds' => 1,
        ]);
    }

    /**
     * "Apna Bana Le" as the wrapper returns it.
     *
     * The important detail: `artists.all` credits the composer, the lyricist and
     * two film actors, and does NOT mention Arijit Singh — who sings it. He is
     * only in `artists.primary`, tagged `primary_artists` like everyone else
     * there. Neither array alone describes the record.
     *
     * @param  array<array-key, mixed>  $overrides
     * @return array<array-key, mixed>
     */
    private function apnaBanaLe(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'wW3g85C5',
            'name' => 'Apna Bana Le',
            'duration' => 261,
            'language' => 'hindi',
            'artists' => [
                'primary' => [
                    ['id' => '458681', 'name' => 'Amitabh Bhattacharya', 'role' => 'primary_artists'],
                    ['id' => '461968', 'name' => 'Sachin-Jigar', 'role' => 'primary_artists'],
                    ['id' => '459320', 'name' => 'Arijit Singh', 'role' => 'primary_artists'],
                ],
                'featured' => [],
                'all' => [
                    ['id' => '461968', 'name' => 'Sachin-Jigar', 'role' => 'music'],
                    ['id' => '458681', 'name' => 'Amitabh Bhattacharya', 'role' => 'lyricist'],
                    ['id' => '511656', 'name' => 'Varun Dhawan', 'role' => 'starring'],
                    ['id' => '701752', 'name' => 'Kriti Sanon', 'role' => 'starring'],
                ],
            ],
        ], $overrides);
    }

    private function adapter(): JioSaavnAdapter
    {
        return app(JioSaavnAdapter::class);
    }

    /** @param array<array-key, mixed> $song */
    private function fakeSong(array $song): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [$song]])]);
    }

    public function test_it_reads_credits_from_all_three_artist_arrays(): void
    {
        $this->fakeSong($this->apnaBanaLe());

        $song = $this->adapter()->getSong('wW3g85C5');

        $this->assertNotNull($song);

        $byRole = [];

        foreach ($song->credits as $credit) {
            $byRole[$credit->role->value][] = $credit->name;
        }

        // From artists.primary — the only array that mentions the singer.
        $this->assertSame(
            ['Amitabh Bhattacharya', 'Sachin-Jigar', 'Arijit Singh'],
            $byRole[CreditRole::Primary->value],
        );
        // From artists.all — the only array that states real roles.
        $this->assertSame(['Sachin-Jigar'], $byRole[CreditRole::Composer->value]);
        $this->assertSame(['Amitabh Bhattacharya'], $byRole[CreditRole::Lyricist->value]);
        $this->assertSame(['Varun Dhawan', 'Kriti Sanon'], $byRole[CreditRole::Actor->value]);
    }

    /**
     * The same person legitimately holds two roles, and that is two credits.
     *
     * Sachin-Jigar is a primary credit AND the composer here. Collapsing on the
     * ID alone would lose one of the two facts; collapsing on nothing would
     * duplicate the person when both arrays say the same thing.
     */
    public function test_one_artist_can_hold_several_roles_but_not_the_same_role_twice(): void
    {
        $payload = $this->apnaBanaLe();
        // Repeat an entry verbatim — a shape the provider does produce.
        $payload['artists']['all'][] = ['id' => '461968', 'name' => 'Sachin-Jigar', 'role' => 'music'];

        $this->fakeSong($payload);

        $credits = $this->adapter()->getSong('wW3g85C5')->credits;

        $sachinJigar = array_values(array_filter(
            $credits,
            static fn (ProviderArtistCredit $c): bool => $c->externalId === '461968',
        ));

        $this->assertCount(2, $sachinJigar, 'expected exactly a primary and a composer credit');
        $this->assertEqualsCanonicalizing(
            [CreditRole::Primary, CreditRole::Composer],
            array_map(static fn (ProviderArtistCredit $c): CreditRole => $c->role, $sachinJigar),
        );
    }

    /**
     * A role the enum does not know is dropped, not guessed at.
     *
     * Filing an unmapped role as `primary` would put a stranger on an artist's
     * page, which is worse than a credit we did not store.
     */
    public function test_an_unrecognised_role_is_skipped(): void
    {
        $payload = $this->apnaBanaLe();
        $payload['artists']['all'][] = ['id' => '999999', 'name' => 'Someone Else', 'role' => 'conductor'];

        $this->fakeSong($payload);

        $credits = $this->adapter()->getSong('wW3g85C5')->credits;

        $this->assertNotContains(
            '999999',
            array_map(static fn (ProviderArtistCredit $c): string => $c->externalId, $credits),
        );
    }

    /**
     * A name that is really a whole credit line keeps its ID and loses its name.
     *
     * The ID still resolves against `provider_artist_mappings`; the name would
     * otherwise be created as an artist called "Sachin-Jigar|Arijit Singh".
     */
    public function test_a_credit_line_masquerading_as_a_name_is_rejected_but_the_id_survives(): void
    {
        $payload = $this->apnaBanaLe();
        $payload['artists']['all'][] = [
            'id' => '888888',
            'name' => 'Sachin-Jigar|Arijit Singh',
            'role' => 'singer',
        ];

        $this->fakeSong($payload);

        $credits = $this->adapter()->getSong('wW3g85C5')->credits;

        $suspect = array_values(array_filter(
            $credits,
            static fn (ProviderArtistCredit $c): bool => $c->externalId === '888888',
        ));

        $this->assertCount(1, $suspect);
        $this->assertNull($suspect[0]->name);
        $this->assertSame(CreditRole::Singer, $suspect[0]->role);
    }

    /**
     * Credits come back in a stable order regardless of how the provider
     * arranged its own arrays.
     *
     * Load-bearing: the credit list is folded into
     * ProviderSongData::checksum(), so an order that followed the provider's
     * whim would make every song look changed on every refresh and rewrite the
     * whole catalog each sync.
     */
    public function test_credit_order_and_checksum_do_not_depend_on_provider_array_order(): void
    {
        $this->fakeSong($this->apnaBanaLe());
        $first = $this->adapter()->getSong('wW3g85C5');

        $shuffled = $this->apnaBanaLe();
        $shuffled['artists']['primary'] = array_reverse($shuffled['artists']['primary']);
        $shuffled['artists']['all'] = array_reverse($shuffled['artists']['all']);

        $this->fakeSong($shuffled);
        $second = $this->adapter()->getSong('wW3g85C5');

        $this->assertSame(
            array_map(static fn (ProviderArtistCredit $c): string => $c->key(), $first->credits),
            array_map(static fn (ProviderArtistCredit $c): string => $c->key(), $second->credits),
        );
        $this->assertSame($first->checksum(), $second->checksum());
    }

    /**
     * The bug this whole class of change exists for.
     *
     * No `singer` role anywhere in the payload, so the old rule took
     * `artists.primary[0]` — the lyricist — and the song was displayed and filed
     * under the person who wrote its words.
     */
    public function test_the_display_artist_is_not_the_lyricist_when_nobody_is_tagged_singer(): void
    {
        $this->fakeSong($this->apnaBanaLe());

        $song = $this->adapter()->getSong('wW3g85C5');

        $this->assertSame('Arijit Singh', $song->artist);
    }

    /** An explicit singer credit still wins over the elimination rule. */
    public function test_an_explicit_singer_credit_takes_precedence(): void
    {
        $payload = $this->apnaBanaLe();
        $payload['artists']['all'][] = ['id' => '459320', 'name' => 'Arijit Singh', 'role' => 'singer'];

        $this->fakeSong($payload);

        $this->assertSame('Arijit Singh', $this->adapter()->getSong('wW3g85C5')->artist);
    }

    /**
     * With nothing to eliminate on, the rule declines to have an opinion.
     *
     * A payload carrying no off-mic roles gives no evidence about who performed,
     * so the pick falls through to the original first-primary fallback rather
     * than inventing a preference.
     */
    public function test_with_no_off_mic_credits_the_first_primary_artist_is_kept(): void
    {
        $this->fakeSong([
            'id' => 'plain1',
            'name' => 'A Single',
            'duration' => 200,
            'artists' => [
                'primary' => [
                    ['id' => '1', 'name' => 'First Named', 'role' => 'primary_artists'],
                    ['id' => '2', 'name' => 'Second Named', 'role' => 'primary_artists'],
                ],
                'all' => [],
            ],
        ]);

        $this->assertSame('First Named', $this->adapter()->getSong('plain1')->artist);
    }

    /**
     * A track credited only to writers and cast keeps a writer as its label.
     *
     * There is no performer to promote, and `songs.artist_id` is NOT NULL — so
     * the fallback has to produce a name rather than nothing.
     */
    public function test_a_track_with_only_off_mic_credits_still_gets_a_display_artist(): void
    {
        $this->fakeSong([
            'id' => 'instr1',
            'name' => 'Background Score',
            'duration' => 120,
            'artists' => [
                'primary' => [['id' => '10', 'name' => 'Score Composer', 'role' => 'primary_artists']],
                'all' => [
                    ['id' => '10', 'name' => 'Score Composer', 'role' => 'music'],
                    ['id' => '11', 'name' => 'Lead Actor', 'role' => 'starring'],
                ],
            ],
        ]);

        $this->assertSame('Score Composer', $this->adapter()->getSong('instr1')->artist);
    }
}
