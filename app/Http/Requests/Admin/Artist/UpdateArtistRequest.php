<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Artist;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateArtistRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string'],
            'image' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'is_verified' => ['sometimes', 'boolean'],
            'dominant_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'dominant_language' => ['sometimes', 'nullable', 'string', 'max:64'],
            'birth_date' => ['sometimes', 'nullable', 'string', 'max:32'],
            'facebook_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'twitter_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'wiki_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }
}
