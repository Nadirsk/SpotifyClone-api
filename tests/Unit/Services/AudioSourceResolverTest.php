<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AudioQuality;
use App\Models\Song;
use App\Services\Catalog\AudioSourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bitrate ladder is derived by rewriting a suffix on the URL the sync
 * stored — a *convention* of the provider's CDN, not a contract. These tests
 * are what make a change to it fail loudly instead of silently serving the
 * wrong bitrate.
 */
final class AudioSourceResolverTest extends TestCase
{
    private const SOURCE = 'https://aac.saavncdn.com/450/f467e05e2825cec2203546333e0d0550_320.mp4';

    private AudioSourceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new AudioSourceResolver;
    }

    private function song(?string $url): Song
    {
        $song = new Song;
        $song->preview_url = $url;
        $song->title = 'Gehra Hua';
        $song->id = 'song-id';

        return $song;
    }

    /** @return array<string, array{AudioQuality, string}> */
    public static function tiers(): array
    {
        return [
            'low' => [AudioQuality::Low, '_48.mp4'],
            'normal' => [AudioQuality::Normal, '_96.mp4'],
            'high' => [AudioQuality::High, '_160.mp4'],
            'very high' => [AudioQuality::VeryHigh, '_320.mp4'],
            // No lossless variant exists; the top rung is what is served.
            'lossless degrades' => [AudioQuality::Lossless, '_320.mp4'],
        ];
    }

    #[DataProvider('tiers')]
    public function test_each_tier_rewrites_the_bitrate_suffix(AudioQuality $quality, string $suffix): void
    {
        $resolved = $this->resolver->resolve($this->song(self::SOURCE), $quality);

        $this->assertNotNull($resolved);
        $this->assertStringEndsWith($suffix, $resolved['url']);
        $this->assertTrue($resolved['derived']);
    }

    public function test_only_the_trailing_suffix_is_rewritten(): void
    {
        // The path segment contains digits that must not be mistaken for the
        // bitrate marker — this is the regression the anchored pattern prevents.
        $resolved = $this->resolver->resolve($this->song(self::SOURCE), AudioQuality::Low);

        $this->assertSame(
            'https://aac.saavncdn.com/450/f467e05e2825cec2203546333e0d0550_48.mp4',
            $resolved['url'],
        );
    }

    public function test_an_unrecognised_url_is_returned_untouched_and_marked_underived(): void
    {
        $resolved = $this->resolver->resolve($this->song('https://example.test/a.mp3'), AudioQuality::Low);

        $this->assertNotNull($resolved);
        $this->assertSame('https://example.test/a.mp3', $resolved['url']);
        // The caller must not report the requested tier as delivered.
        $this->assertFalse($resolved['derived']);
        $this->assertSame(AudioQuality::VeryHigh, $resolved['quality']);
    }

    public function test_a_song_with_no_source_resolves_to_null(): void
    {
        $this->assertNull($this->resolver->resolve($this->song(null), AudioQuality::Normal));
        $this->assertNull($this->resolver->resolve($this->song('   '), AudioQuality::Normal));
    }

    public function test_has_variants_reports_whether_the_ladder_is_derivable(): void
    {
        $this->assertTrue($this->resolver->hasVariants($this->song(self::SOURCE)));
        $this->assertFalse($this->resolver->hasVariants($this->song('https://example.test/a.mp3')));
        $this->assertFalse($this->resolver->hasVariants($this->song(null)));
    }

    public function test_the_download_filename_keeps_the_extension_and_drops_illegal_characters(): void
    {
        $song = $this->song(self::SOURCE);
        $song->title = 'Gehra Hua (From "Dhurandhar")';

        $filename = $this->resolver->downloadFilename($song, self::SOURCE);

        $this->assertStringEndsWith('.mp4', $filename);
        $this->assertStringNotContainsString('"', $filename);
        $this->assertStringNotContainsString('/', $filename);
    }

    public function test_zero_width_characters_are_stripped_from_the_filename(): void
    {
        // The live catalog has artist names with a leading U+2060, which
        // otherwise survives every other filter and mangles the ASCII fallback.
        $song = $this->song(self::SOURCE);
        $song->title = "\u{2060}Gehra Hua";

        $this->assertSame('Gehra Hua.mp4', $this->resolver->downloadFilename($song, self::SOURCE));
    }

    public function test_a_title_that_sanitises_to_nothing_falls_back_to_the_song_id(): void
    {
        $song = $this->song(self::SOURCE);
        $song->title = '///';

        $this->assertSame('song-id.mp4', $this->resolver->downloadFilename($song, self::SOURCE));
    }

    public function test_clamping_is_symmetric_with_the_plan_ceiling(): void
    {
        $this->assertSame(
            AudioQuality::Normal,
            AudioQuality::VeryHigh->clampTo(AudioQuality::Normal),
        );

        // Already below the ceiling — left alone rather than raised to it.
        $this->assertSame(
            AudioQuality::Low,
            AudioQuality::Low->clampTo(AudioQuality::VeryHigh),
        );
    }
}
