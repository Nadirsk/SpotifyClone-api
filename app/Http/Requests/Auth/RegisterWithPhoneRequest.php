<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterWithPhoneRequest extends FormRequest
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
            // Bare 10 digits, matching SendOtpRequest/VerifyOtpRequest — the
            // gateway behind phone sign-up is India-only. Soft-deleted rows
            // are excluded for the same reason as RegisterRequest::rules().
            'phone' => ['required', 'digits:10', Rule::unique('users', 'phone')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
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
            'phone.unique' => 'An account with this phone number already exists.',
        ];
    }
}
