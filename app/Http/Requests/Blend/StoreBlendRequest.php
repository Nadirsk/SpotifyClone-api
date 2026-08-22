<?php

declare(strict_types=1);

namespace App\Http\Requests\Blend;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBlendRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Creating a Blend needs no policy — the route's auth:sanctum
        // middleware is the whole check, and the creator is taken from the token.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Optional: BlendService::create() falls back to "{name}'s Blend"
            // when omitted, the same way a new playlist gets a numbered default title.
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
