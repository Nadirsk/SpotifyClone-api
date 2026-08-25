<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Language;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // ISO 639-1 where one exists — see the Language model's migration.
            'code' => ['required', 'string', 'max:10', Rule::unique('languages', 'code')],
        ];
    }
}
