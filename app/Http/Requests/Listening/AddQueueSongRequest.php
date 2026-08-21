<?php

declare(strict_types=1);

namespace App\Http\Requests\Listening;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddQueueSongRequest extends FormRequest
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
            'song_id' => [
                'required',
                'uuid',
                // Songs are soft-deleted, so the plain `exists` rule would still
                // accept one that has been removed from the catalog.
                Rule::exists('songs', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function songId(): string
    {
        return (string) $this->validated('song_id');
    }
}
