<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concert;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateConcertRequest extends FormRequest
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
            'venue_id' => ['sometimes', 'required', 'string', 'exists:venues,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'date' => ['sometimes', 'required', 'date'],
            'date_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'event_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'genres' => ['sometimes', 'nullable', 'array'],
            'genres.*' => ['string', 'max:64'],
            'vendors' => ['sometimes', 'nullable', 'array'],
            'vendors.*.name' => ['required', 'string', 'max:255'],
            'vendors.*.price' => ['required', 'string', 'max:64'],
            'image' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }
}
