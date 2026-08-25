<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Album;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAlbumRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            'language_id' => ['sometimes', 'nullable', 'string', 'exists:languages,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'cover_image' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'release_date' => ['sometimes', 'nullable', 'date'],
            'is_explicit' => ['sometimes', 'boolean'],
        ];
    }
}
