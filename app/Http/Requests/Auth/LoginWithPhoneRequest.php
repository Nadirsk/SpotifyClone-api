<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginWithPhoneRequest extends FormRequest
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
        // Bare 10 digits, matching SendOtpRequest/VerifyOtpRequest — the
        // gateway behind phone sign-up/login is India-only.
        return [
            'phone' => ['required', 'digits:10'],
        ];
    }
}
