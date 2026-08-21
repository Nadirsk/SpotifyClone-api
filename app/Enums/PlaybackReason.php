<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a room's playback state changed.
 *
 * Every case produces the *same* payload — the full authoritative state — and
 * participants apply all of them through one code path, because "seek to 2:45
 * and keep playing" and "load track 4 and start at 0" are the same instruction
 * with different numbers in it. The reason is carried anyway for two things a
 * bare state snapshot cannot express: what the room's activity feed says
 * happened ("Asha skipped ahead"), and which arrivals a client may coalesce —
 * a burst of `Seek` while a host drags the scrubber is safe to thin out, a
 * `SongChanged` never is.
 *
 * `Next`, `Previous` and `TrackEnded` are deliberately distinct from
 * `SongChanged` even though the state they carry is identical. A participant
 * does not need to know the difference, but the host's own UI and the tests do:
 * `TrackEnded` is the one transition no human pressed, and it is the one that
 * must never fire from two clients at once (see ListeningRoomService).
 */
enum PlaybackReason: string
{
    case Play = 'play';
    case Pause = 'pause';
    case Seek = 'seek';
    case SongChanged = 'song_changed';
    case Next = 'next';
    case Previous = 'previous';
    case TrackEnded = 'track_ended';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Whether this transition puts a different track in front of the room. */
    public function changesSong(): bool
    {
        return match ($this) {
            self::SongChanged, self::Next, self::Previous, self::TrackEnded => true,
            self::Play, self::Pause, self::Seek => false,
        };
    }
}
