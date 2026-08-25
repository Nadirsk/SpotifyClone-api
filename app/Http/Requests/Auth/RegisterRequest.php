<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            /*
             | Soft-deleted rows are excluded — EloquentUserRepository::delete()
             | already frees a deleted user's email at the DB level, but this
             | keeps the check correct even for rows soft-deleted before that
             | existed, or by anything that bypasses the repository.
             */
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            /*
             | `defaults()` so a future central hardening (uncompromised checks,
             | mixed case, symbols) applies everywhere at once; `min:8` is
             | restated so the floor holds even if the default is ever relaxed.
             */
            'password' => ['required', 'string', 'confirmed', Password::defaults(), 'min:8'],
            'country' => ['nullable', 'string', 'size:2'],
            'language' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
