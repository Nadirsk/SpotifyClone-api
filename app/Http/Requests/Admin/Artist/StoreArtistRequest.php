<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Artist;

use Illuminate\Foundation\Http\FormRequest;

final class StoreArtistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'image' => ['nullable', 'url', 'max:2048'],
            'country' => ['nullable', 'string', 'size:2'],
            'is_verified' => ['sometimes', 'boolean'],
            'dominant_type' => ['nullable', 'string', 'max:64'],
            'dominant_language' => ['nullable', 'string', 'max:64'],
            // Not a date column — see Artist model's docblock on birth_date:
            // providers state this in whatever format they have (a full date,
            // a bare year, ...), so it is stored and validated as free text.
            'birth_date' => ['nullable', 'string', 'max:32'],
            'facebook_url' => ['nullable', 'url', 'max:2048'],
            'twitter_url' => ['nullable', 'url', 'max:2048'],
            'wiki_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
