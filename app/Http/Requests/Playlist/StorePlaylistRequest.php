<?php

declare(strict_types=1);

namespace App\Http\Requests\Playlist;

use App\Enums\PlaylistVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Creating a playlist needs no policy — the route's auth:sanctum
        // middleware is the whole check, and the owner is taken from the token.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', 'string', Rule::in(PlaylistVisibility::values())],
            'cover_image' => ['nullable', 'url'],
        ];
    }
}
