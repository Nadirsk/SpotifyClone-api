<?php

declare(strict_types=1);

namespace App\Http\Requests\Listening;

use App\DTO\PlaybackCommand;
use App\Enums\PlaybackReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePlaybackRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Host-only is enforced by ListeningRoomPolicy from the controller.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(PlaybackReason::values())],

            /*
             | Nullable, for the one legitimate case: a host who cleared their
             | queue and now has nothing loaded. `present` rather than optional so
             | that a client omitting the field is a validation failure instead of
             | silently blanking the room's current track.
             */
            'song_id' => [
                'present',
                'nullable',
                'uuid',
                // Songs are soft-deleted, so a plain `exists` would accept one
                // that has been pulled from the catalog.
                Rule::exists('songs', 'id')->whereNull('deleted_at'),
            ],

            /*
             | Milliseconds, integer. Capped at 24 hours rather than left
             | unbounded: the column is an unsigned int, and a client sending a
             | garbage float-turned-huge-integer would otherwise fail at the
             | database with a 500 instead of here with a 422.
             */
            'position_ms' => ['required', 'integer', 'min:0', 'max:86400000'],

            'is_playing' => ['required', 'boolean'],

            /*
             | When the position was measured, on the server's clock. Optional —
             | a client that has not estimated the offset yet omits it and the
             | server stamps arrival instead. Only sanity-bounded here (a
             | plausible epoch in milliseconds); the meaningful clamp is
             | ListeningRoomService::measuredAt, which compares it to the
             | server's own clock. See PlaybackCommand for why this is accepted
             | from a client at all.
             */
            'position_at_ms' => ['sometimes', 'nullable', 'integer', 'min:1000000000000'],
        ];
    }

    public function command(): PlaybackCommand
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return PlaybackCommand::fromValidated($validated);
    }
}
