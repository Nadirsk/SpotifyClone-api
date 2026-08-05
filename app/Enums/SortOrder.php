<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sort options from 05_API_SPECIFICATION §16.
 */
enum SortOrder: string
{
    case Relevance = 'relevance';
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Popularity = 'popularity';
    case Alphabetical = 'alphabetical';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
