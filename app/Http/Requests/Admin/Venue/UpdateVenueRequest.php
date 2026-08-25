<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Venue;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateVenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
