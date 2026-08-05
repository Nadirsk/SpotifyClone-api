<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaylistVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Unlisted = 'unlisted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
