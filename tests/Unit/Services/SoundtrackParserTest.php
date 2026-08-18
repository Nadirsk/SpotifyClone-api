<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Catalog\SoundtrackParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parser is deliberately conservative — over-matching invents films that do
 * not exist and fills the browse hub with "(Lofi Mix)" entries, which is worse
 * than under-matching. Half of these cases exist to pin the *rejections*.
 */
final class SoundtrackParserTest extends TestCase
{
    private SoundtrackParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new SoundtrackParser;
    }

    /** @return array<string, array{string, string|null}> */
    public static function titles(): array
    {
        return [
            'standard parenthetical' => ['Gehra Hua (From "Dhurandhar")', 'Dhurandhar'],
            'square brackets' => ['Bandeya [From "Dil Juunglee"]', 'Dil Juunglee'],
            'curly quotes' => ['Tum Hi Ho (From “Aashiqui 2”)', 'Aashiqui 2'],
            'single quotes' => ["Kesariya (From 'Brahmastra')", 'Brahmastra'],
            'lowercase keyword' => ['Something (from "A Film")', 'A Film'],
            'extra inner spacing' => ['Song (  From   "  Spaced Out  "  )', 'Spaced Out'],
            'film name with punctuation' => ['Sooha Saha (From "Highway")', 'Highway'],

            // Rejections — everything below must parse to null.
            'plain title' => ['Some Indie Single', null],
            'remix parenthetical' => ['Tum Hi Ho (Lofi Mix)', null],
            'live parenthetical' => ['Channa Mereya (Live)', null],
            'quoted but not a film credit' => ['Song ("Reprise")', null],
            'from without quotes' => ['Song (From Dhurandhar)', null],
            'empty film name' => ['Song (From "")', null],
            'empty string' => ['', null],
        ];
    }

    #[DataProvider('titles')]
    public function test_it_extracts_only_a_real_film_credit(string $title, ?string $expected): void
    {
        $this->assertSame($expected, $this->parser->filmFrom($title));
    }

    public function test_null_in_null_out(): void
    {
        $this->assertNull($this->parser->filmFrom(null));
        $this->assertFalse($this->parser->isSoundtrack(null));
    }

    public function test_an_absurdly_long_capture_is_rejected_as_a_sentence(): void
    {
        $long = str_repeat('a', 200);

        $this->assertNull($this->parser->filmFrom("Song (From \"{$long}\")"));
    }

    public function test_the_credit_can_be_stripped_for_display(): void
    {
        $this->assertSame(
            'Gehra Hua',
            $this->parser->withoutFilmCredit('Gehra Hua (From "Dhurandhar")'),
        );
    }

    public function test_stripping_a_title_that_is_only_a_credit_leaves_it_alone(): void
    {
        // Better to show a slightly redundant title than a blank row.
        $title = '(From "Dhurandhar")';

        $this->assertSame($title, $this->parser->withoutFilmCredit($title));
    }
}
