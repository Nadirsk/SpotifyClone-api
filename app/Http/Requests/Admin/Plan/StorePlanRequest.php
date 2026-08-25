<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Plan;

use App\Enums\AudioQuality;
use App\Enums\SubscriptionPlan;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds a row for a plan identity that exists in {@see SubscriptionPlan} but
 * has none yet — see the `plans` migration for why this never invents a new
 * identity: `plan` must be one of the enum's own values, unique in this
 * table, and there are only ever as many rows as enum cases.
 */
final class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only `max_audio_quality` is pinned here — every other entitlements key
     * is open-ended so the admin panel can add a brand-new capability without
     * a deploy. {@see withValidator()} enforces the shape (boolean value,
     * `snake_case` key) for whatever keys actually show up.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::in(SubscriptionPlan::values()), Rule::unique('plans', 'plan')],
            'name' => ['required', 'string', 'max:255'],
            'tagline' => ['required', 'string', 'max:255'],
            'accounts' => ['required', 'integer', 'min:1', 'max:255'],
            'max_sessions' => ['nullable', 'integer', 'min:1', 'max:255'],
            'reference_price_inr' => ['nullable', 'integer', 'min:0'],
            'reference_price_usd' => ['nullable', 'integer', 'min:0'],
            'entitlements' => ['required', 'array'],
            'entitlements.max_audio_quality' => ['required', 'string', Rule::in(AudioQuality::values())],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            EntitlementsShape::validate($validator, (array) $this->input('entitlements', []));
        });
    }

    /**
     * The raw entitlements array, already shape-checked by {@see withValidator()}.
     * Not part of `validated()` because that only returns the dot-notation
     * keys declared in {@see rules()} — every other key would be silently
     * dropped, which would defeat the point of an open-ended entitlement.
     *
     * @return array<string, bool|string>
     */
    public function entitlementsPayload(): array
    {
        return (array) $this->input('entitlements', []);
    }
}
