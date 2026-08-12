<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class SendOtpRequest extends FormRequest
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
        // 10 bare digits, no country code — the gateway is India-only, and
        // the vendor call always prefixes the number itself.
        return [
            'phone' => ['required', 'digits:10'],
        ];
    }
}
