<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Subscription;

use App\Enums\SubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSubscriptionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(SubscriptionStatus::values())],
        ];
    }
}
