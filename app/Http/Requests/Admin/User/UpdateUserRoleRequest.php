<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's auth:sanctum + admin middleware is the whole check;
        // the self-modification guard lives in the controller, which is the
        // one place that also holds the acting admin's own id.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(UserRole::values())],
        ];
    }
}
