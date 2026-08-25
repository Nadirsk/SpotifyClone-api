<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Song;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSongRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's auth:sanctum + admin middleware is the whole check.
        return true;
    }

    /**
     * Every field is `sometimes` so a partial update only touches what it sends.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'artist_id' => ['sometimes', 'required', 'string', 'exists:artists,id'],
            'album_id' => ['sometimes', 'nullable', 'string', 'exists:albums,id'],
            'genre_id' => ['sometimes', 'nullable', 'string', 'exists:genres,id'],
            'language_id' => ['sometimes', 'nullable', 'string', 'exists:languages,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'track_number' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'duration' => ['sometimes', 'required', 'integer', 'min:0'],
            'isrc' => ['sometimes', 'nullable', 'string', 'max:15'],
            'release_date' => ['sometimes', 'nullable', 'date'],
            'preview_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'external_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'copyright' => ['sometimes', 'nullable', 'string'],
            'is_explicit' => ['sometimes', 'boolean'],
            'has_lyrics' => ['sometimes', 'boolean'],
        ];
    }
}
