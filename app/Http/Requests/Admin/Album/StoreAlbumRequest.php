<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Album;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAlbumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'language_id' => ['nullable', 'string', 'exists:languages,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'url', 'max:2048'],
            'release_date' => ['nullable', 'date'],
            'is_explicit' => ['sometimes', 'boolean'],
        ];
    }
}
