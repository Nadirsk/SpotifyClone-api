<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use App\Enums\SortOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for GET /search and GET /search/suggest.
 *
 * A blank term is a client error, not an empty result set: without this the
 * engine would happily return nothing with a 200 and the caller would have no
 * way to tell "no matches" from "you forgot to send q".
 */
final class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:1', 'max:100'],
            // Types come from config so the API and the engine can never drift.
            'type' => ['nullable', 'string', Rule::in(array_keys((array) config('search.types')))],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('music.pagination.max_limit')],
            'sort' => ['nullable', 'string', Rule::in(SortOrder::values())],
            /*
             | Opt-in permission to spend provider requests on this search.
             | Absent or false means answer from the local catalog alone —
             | see SearchQuery::$sync for why the default has to be "no".
             */
            'sync' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Trim before validating so that `?q=%20` fails `required` rather than
     * slipping through `min:1` as a one-character term.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('q')) {
            $this->merge(['q' => trim((string) $this->query('q'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'A search term is required.',
        ];
    }
}
