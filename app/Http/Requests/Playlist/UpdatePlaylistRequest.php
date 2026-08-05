<?php

declare(strict_types=1);

namespace App\Http\Requests\Playlist;

use App\Enums\PlaylistVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced by PlaylistPolicy from the controller.
        return true;
    }

    /**
     * Every field is `sometimes` so a partial update only touches what it sends
     * — omitting `description` must not blank it.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visibility' => ['sometimes', 'required', 'string', Rule::in(PlaylistVisibility::values())],
            'cover_image' => ['sometimes', 'nullable', 'url'],
        ];
    }
}
