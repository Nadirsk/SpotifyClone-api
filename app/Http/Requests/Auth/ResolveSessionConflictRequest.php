<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `POST /auth/sessions/resolve` — the second half of a login that hit
 * the plan's device cap.
 *
 * Unauthenticated by necessity: the caller has no token yet, which is the whole
 * problem. `resolution_token` is what stands in for one, and
 * `DeviceSessionService::resolveConflict()` is what verifies it — this class
 * only checks the shape.
 */
final class ResolveSessionConflictRequest extends FormRequest
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
            /*
             | Length is not asserted: the ticket is minted by us at a fixed 64
             | characters, but pinning that here would turn a change to the
             | generator into a 422 on a valid ticket. Verification is the
             | service's job either way.
             */
            'resolution_token' => ['required', 'string', 'max:255'],
            /*
             | A list, because one device is not always enough to free — see
             | `resolveConflict()`. No `max` on the array: an account that has
             | accumulated sessions since before the cap existed may legitimately
             | need to clear dozens, and capping the list would leave it unable
             | to sign in at all.
             */
            'session_ids' => ['required', 'array', 'min:1'],
            /*
             | `personal_access_tokens.id` is a plain auto-increment integer.
             | Deliberately not an `exists:` rule — that would make this endpoint
             | answer "is there a session with this id" for ids belonging to
             | other accounts. The service looks them up scoped to the ticket's
             | own user and 404s otherwise.
             */
            'session_ids.*' => ['integer', 'min:1'],
        ];
    }
}
