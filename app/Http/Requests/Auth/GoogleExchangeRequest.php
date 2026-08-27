<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `POST /auth/google/exchange` — the frontend's landing page trades the
 * one-time code from `AuthController::googleCallback()`'s redirect for the real
 * session. Unauthenticated by necessity: the caller has no token yet, which is
 * the whole problem. `AuthService::exchangeGoogleCode()` is what verifies the
 * code; this class only checks the shape.
 */
final class GoogleExchangeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:255'],
        ];
    }
}
