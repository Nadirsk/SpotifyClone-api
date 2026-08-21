<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a member of a listening room may do.
 *
 * V1 is a strict split: the host drives playback and everyone else listens.
 * The enum exists rather than a boolean `is_host` column because the roles are
 * already known to be more than two — shared control is the first thing anyone
 * asks for after using this — and widening an enum is a migration, while
 * widening a boolean is a rewrite of every call site that read it.
 */
enum ListeningRole: string
{
    case Host = 'host';
    case Participant = 'participant';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Whether this role may play, pause, seek, skip or edit the queue. */
    public function controlsPlayback(): bool
    {
        return $this === self::Host;
    }
}
