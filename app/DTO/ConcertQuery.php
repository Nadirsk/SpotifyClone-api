<?php

declare(strict_types=1);

namespace App\DTO;

use Illuminate\Http\Request;

/**
 * Filters for GET /concerts. Deliberately its own DTO rather than reusing
 * `CatalogQuery`: concerts are not a catalog entity (no genre/language table,
 * no popularity), and their filters — city, a genre tag, a date range —
 * mirror `frontend/src/app/(player)/concerts/page.tsx`'s own filter row.
 *
 * No pagination: the reference screen filters one in-memory list of a
 * curated, seeded size, not a paginated feed.
 */
final readonly class ConcertQuery
{
    public function __construct(
        public ?string $city = null,
        public ?string $genre = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            city: self::nullIfBlank($request->query('city')),
            genre: self::nullIfBlank($request->query('genre')),
            from: self::nullIfBlank($request->query('from')),
            to: self::nullIfBlank($request->query('to')),
        );
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
