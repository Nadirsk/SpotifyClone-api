<?php

declare(strict_types=1);

namespace App\Http\Requests\Blend;

use Illuminate\Foundation\Http\FormRequest;

final class InviteToBlendRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is checked by the controller via Gate::authorize('invite', ...).
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'exists:users,id'],
        ];
    }
}
