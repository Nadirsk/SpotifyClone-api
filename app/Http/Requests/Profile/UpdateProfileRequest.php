<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\AudioQuality;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A user may only ever reach their own profile — the route resolves the
        // subject from the token, never from the payload.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $user = $this->currentUser();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->getKey()),
            ],
            /*
             | Same shape as registration's phone field (RegisterWithPhoneRequest)
             | — 10 digits, no country code — but nullable: an email-registered
             | account has no phone until it sets one here, and clearing it back
             | to null has to stay possible.
             */
            'phone' => [
                'sometimes',
                'nullable',
                'digits:10',
                Rule::unique('users', 'phone')->ignore($user?->getKey()),
            ],
            'country' => ['nullable', 'string', 'size:2'],
            /*
             | Not nullable like `country`: the `users.language` column is
             | NOT NULL with a default of 'en' (it drives SetLocale
             | middleware), so a null here would reach the database as a raw
             | constraint violation instead of a clean validation error.
             */
            'language' => ['sometimes', 'required', 'string', 'max:10'],
            /*
             | Accepted from any plan, free included. This is the listener's
             | preference, and the plan ceiling is applied when the stream is
             | resolved (SubscriptionService::effectiveQualityFor) — rejecting
             | `very_high` here would mean a listener who upgrades has to go
             | back and set it again, and a listener who lapses loses it.
             */
            'audio_quality' => ['sometimes', 'required', 'string', Rule::in(AudioQuality::values())],
            'offline_enabled' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'required', 'string', 'confirmed', Password::defaults()],
            'current_password' => [
                /*
                 | Only demanded when a password is actually being changed, and
                 | only for accounts that have one — an OAuth-only user has a
                 | null password and would otherwise be unable to ever set one.
                 */
                Rule::requiredIf(fn (): bool => $this->filled('password') && $user?->password !== null),
                'string',
                /*
                 | The guard has to be named: this is a stateless bearer-token
                 | API, so the default `web` guard has no authenticated user and
                 | the rule would reject every correct password.
                 */
                'current_password:sanctum',
            ],
        ];
    }

    /**
     * `current_password` is a proof of identity, not a column.
     * `Model::preventSilentlyDiscardingAttributes()` would throw if it reached
     * the update, so it is stripped here rather than in the service.
     *
     * @return array<string, mixed>
     */
    public function attributesToUpdate(): array
    {
        return Arr::except($this->validated(), ['current_password']);
    }

    private function currentUser(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }
}
