<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Venue;

use Illuminate\Foundation\Http\FormRequest;

final class StoreVenueRequest extends FormRequest
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
            'city' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
