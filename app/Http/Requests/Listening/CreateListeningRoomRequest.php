<?php

declare(strict_types=1);

namespace App\Http\Requests\Listening;

use App\DTO\PlaybackCommand;
use App\Enums\PlaybackReason;
use App\Rules\SongsExist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a room, optionally with the queue and the moment the host is already
 * in — see ListeningRoomService::create() for why that matters.
 */
final class CreateListeningRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated listener may open a room; the route's auth:sanctum
        // middleware is the whole check.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'song_ids' => ['sometimes', 'array', 'max:1000', new SongsExist],
            'song_ids.*' => ['uuid'],

            /*
             | The host's opening playback state. All-or-nothing: `position_ms`
             | and `is_playing` are required *when a song is named*, because a
             | song with no position is a room that starts everybody at zero while
             | the host is two minutes in — the exact desync this feature exists
             | to remove.
             */
            'current_song_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('songs', 'id')->whereNull('deleted_at'),
            ],
            'position_ms' => ['required_with:current_song_id', 'integer', 'min:0', 'max:86400000'],
            'is_playing' => ['required_with:current_song_id', 'boolean'],
            /* See UpdatePlaybackRequest — same field, same reasoning. */
            'position_at_ms' => ['sometimes', 'nullable', 'integer', 'min:1000000000000'],
        ];
    }

    /** @return list<string> */
    public function songIds(): array
    {
        /** @var list<string> $ids */
        $ids = array_values($this->validated('song_ids') ?? []);

        return $ids;
    }

    /** The state to open the room at, or null to open it idle. */
    public function initialPlayback(): ?PlaybackCommand
    {
        $songId = $this->validated('current_song_id');

        if ($songId === null) {
            return null;
        }

        $measuredAt = $this->validated('position_at_ms');

        return new PlaybackCommand(
            PlaybackReason::SongChanged,
            (string) $songId,
            (int) $this->validated('position_ms'),
            (bool) $this->validated('is_playing'),
            $measuredAt === null ? null : (int) $measuredAt,
        );
    }
}
