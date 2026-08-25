<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Plan;

use App\Enums\AudioQuality;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `plan` (the row's identity) is deliberately absent — it is addressed
     * by the `{plan}` route segment and can never be reassigned here.
     *
     * Only `max_audio_quality` is pinned here — every other entitlements key
     * is open-ended so the admin panel can add a brand-new capability without
     * a deploy. {@see withValidator()} enforces the shape for whatever keys
     * actually show up.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tagline' => ['sometimes', 'required', 'string', 'max:255'],
            'accounts' => ['sometimes', 'required', 'integer', 'min:1', 'max:255'],
            'max_sessions' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
            'reference_price_inr' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reference_price_usd' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'entitlements' => ['sometimes', 'array'],
            'entitlements.max_audio_quality' => ['required_with:entitlements', 'string', Rule::in(AudioQuality::values())],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            if ($this->has('entitlements')) {
                EntitlementsShape::validate($validator, (array) $this->input('entitlements', []));
            }
        });
    }

    /**
     * The raw entitlements array, already shape-checked by {@see withValidator()}.
     * Not part of `validated()` — see {@see StorePlanRequest::entitlementsPayload()}.
     *
     * @return array<string, bool|string>|null
     */
    public function entitlementsPayload(): ?array
    {
        return $this->has('entitlements') ? (array) $this->input('entitlements', []) : null;
    }
}
