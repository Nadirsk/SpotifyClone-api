<?php

declare(strict_types=1);

namespace App\Http\Requests\Playlist;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddPlaylistSongRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced by PlaylistPolicy from the controller.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Songs are soft-deleted, so the plain `exists` rule would still
            // accept one that has been removed from the catalog.
            'song_id' => [
                'required',
                'uuid',
                Rule::exists('songs', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function songId(): string
    {
        return (string) $this->validated('song_id');
    }
}
