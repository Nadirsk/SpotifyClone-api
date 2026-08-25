<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Song;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSongRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's auth:sanctum + admin middleware is the whole check.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'album_id' => ['nullable', 'string', 'exists:albums,id'],
            'genre_id' => ['nullable', 'string', 'exists:genres,id'],
            'language_id' => ['nullable', 'string', 'exists:languages,id'],
            'title' => ['required', 'string', 'max:255'],
            'track_number' => ['nullable', 'integer', 'min:1'],
            'duration' => ['required', 'integer', 'min:0'],
            'isrc' => ['nullable', 'string', 'max:15'],
            'release_date' => ['nullable', 'date'],
            'preview_url' => ['nullable', 'url', 'max:2048'],
            'external_url' => ['nullable', 'url', 'max:2048'],
            'label' => ['nullable', 'string', 'max:255'],
            'copyright' => ['nullable', 'string'],
            'is_explicit' => ['sometimes', 'boolean'],
            'has_lyrics' => ['sometimes', 'boolean'],
        ];
    }
}
