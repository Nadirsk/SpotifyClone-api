<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concert;

use Illuminate\Foundation\Http\FormRequest;

final class StoreConcertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'venue_id' => ['required', 'string', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'date_label' => ['nullable', 'string', 'max:255'],
            'event_count' => ['nullable', 'integer', 'min:1'],
            'genres' => ['nullable', 'array'],
            'genres.*' => ['string', 'max:64'],
            // list<array{name: string, price: string}> — see the Concert
            // migration's docblock for why price is a formatted string
            // ("$45-$120"), not a number.
            'vendors' => ['nullable', 'array'],
            'vendors.*.name' => ['required', 'string', 'max:255'],
            'vendors.*.price' => ['required', 'string', 'max:64'],
            'image' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
