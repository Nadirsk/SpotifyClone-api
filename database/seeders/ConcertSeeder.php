<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Concert;
use App\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * Live events: first-party, seeded data (see `12_SCOPE_OF_WORK.md` — no
 * source document defines this feature at all; built on explicit request).
 * The rows below are `frontend/src/mock/events.ts`'s own fixture content,
 * carried over verbatim so the screen shows the same events it always has —
 * now as real `concerts`/`venues` rows instead of a hardcoded array.
 *
 * Re-running is safe: venues are matched by name, concerts by title + date.
 */
class ConcertSeeder extends Seeder
{
    /** @var list<array{name: string, city: string}> */
    private const VENUES = [
        ['name' => 'Mahalaxmi Race Course', 'city' => 'Mumbai'],
        ['name' => 'Jio World Garden', 'city' => 'Mumbai'],
        ['name' => 'NSCI Dome', 'city' => 'Mumbai'],
        ['name' => 'Bağlantı | Bağlantı Club', 'city' => 'Mumbai'],
        ['name' => 'Shanmukhananda Hall', 'city' => 'Mumbai'],
        ['name' => 'The Finch', 'city' => 'Mumbai'],
        ['name' => 'antiSOCIAL', 'city' => 'Mumbai'],
    ];

    /**
     * @var list<array{
     *     title: string,
     *     venue: string,
     *     date: string,
     *     date_label: ?string,
     *     event_count: ?int,
     *     genres: list<string>,
     *     vendors: list<array{name: string, price: string}>,
     * }>
     */
    private const CONCERTS = [
        [
            'title' => 'The Chainsmokers',
            'venue' => 'Mahalaxmi Race Course',
            'date' => '2026-12-18',
            'date_label' => null,
            'event_count' => null,
            'genres' => ['Electronic', 'Pop', 'Dance'],
            'vendors' => [
                ['name' => 'BookMyShow', 'price' => 'from ₹2,499'],
                ['name' => 'District', 'price' => 'from ₹2,750'],
                ['name' => 'Zomato Live', 'price' => 'from ₹2,999'],
            ],
        ],
        [
            'title' => 'Gorillaz',
            'venue' => 'Jio World Garden',
            'date' => '2027-01-27',
            'date_label' => null,
            'event_count' => null,
            'genres' => ['Alternative', 'Rock', 'Electronic'],
            'vendors' => [
                ['name' => 'BookMyShow', 'price' => 'from ₹4,500'],
                ['name' => 'District', 'price' => 'from ₹4,800'],
            ],
        ],
        [
            'title' => 'The Pretty Reckless, Die Spitz, Foo Fighters',
            'venue' => 'NSCI Dome',
            'date' => '2027-01-31',
            'date_label' => 'Sun 31 Jan',
            'event_count' => 2,
            'genres' => ['Rock', 'Metal', 'Alternative'],
            'vendors' => [['name' => 'BookMyShow', 'price' => 'from ₹3,200']],
        ],
        [
            'title' => 'SOLTO (FR)',
            'venue' => 'Bağlantı | Bağlantı Club',
            'date' => '2026-09-25',
            'date_label' => null,
            'event_count' => null,
            'genres' => ['Electronic', 'Dance'],
            'vendors' => [['name' => 'District', 'price' => 'from ₹1,200']],
        ],
        [
            'title' => 'Fred again..',
            'venue' => 'Mahalaxmi Race Course',
            'date' => '2026-12-08',
            'date_label' => 'Dec 8–9',
            'event_count' => 2,
            'genres' => ['Electronic', 'Dance', 'Indie'],
            'vendors' => [
                ['name' => 'BookMyShow', 'price' => 'from ₹3,999'],
                ['name' => 'Zomato Live', 'price' => 'from ₹4,199'],
            ],
        ],
        [
            'title' => 'Hariharan',
            'venue' => 'Shanmukhananda Hall',
            'date' => '2026-08-22',
            'date_label' => null,
            'event_count' => null,
            'genres' => ['Bollywood', 'Classical'],
            'vendors' => [['name' => 'BookMyShow', 'price' => 'from ₹999']],
        ],
        [
            'title' => 'Ajay-Atul',
            'venue' => 'Jio World Garden',
            'date' => '2026-10-10',
            'date_label' => 'Oct 10–11',
            'event_count' => 2,
            'genres' => ['Bollywood', 'Folk'],
            'vendors' => [
                ['name' => 'BookMyShow', 'price' => 'from ₹1,800'],
                ['name' => 'District', 'price' => 'from ₹1,950'],
            ],
        ],
        [
            'title' => 'Madhubanti Bagchi',
            'venue' => 'The Finch',
            'date' => '2026-11-28',
            'date_label' => '28 Nov–13 Feb',
            'event_count' => 2,
            'genres' => ['Bollywood', 'Pop'],
            'vendors' => [['name' => 'District', 'price' => 'from ₹1,500']],
        ],
    ];

    public function run(): void
    {
        $venues = [];

        foreach (self::VENUES as $data) {
            $venues[$data['name']] = Venue::query()->firstOrCreate(
                ['name' => $data['name']],
                ['city' => $data['city']],
            );
        }

        $created = 0;

        foreach (self::CONCERTS as $data) {
            $venue = $venues[$data['venue']] ?? null;

            if ($venue === null) {
                $this->command?->warn("Skipping \"{$data['title']}\" — venue \"{$data['venue']}\" not seeded.");

                continue;
            }

            $concert = Concert::query()->firstOrCreate(
                ['title' => $data['title'], 'date' => $data['date']],
                [
                    'venue_id' => $venue->getKey(),
                    'date_label' => $data['date_label'],
                    'event_count' => $data['event_count'],
                    'genres' => $data['genres'],
                    'vendors' => $data['vendors'],
                ],
            );

            if ($concert->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info(sprintf(
            'Concerts seeded: %d venues, %d new concerts (%d already existed).',
            count($venues),
            $created,
            count(self::CONCERTS) - $created,
        ));
    }
}
