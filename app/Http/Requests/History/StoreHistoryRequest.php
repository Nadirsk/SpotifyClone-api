<?php

declare(strict_types=1);

namespace App\Http\Requests\History;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The entry is always written for the token's own user, so there is
        // nothing per-record to authorize.
        return true;
    }

    /**
     * Deliberately no `user_id`: accepting one would let any caller write into
     * another account's history.
     *
     * @return array<string, list<mixed>>
     */
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
            'ms_played' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function songId(): string
    {
        return (string) $this->validated('song_id');
    }

    public function msPlayed(): ?int
    {
        $value = $this->validated('ms_played');

        return $value === null ? null : (int) $value;
    }
}
