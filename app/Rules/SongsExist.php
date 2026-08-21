<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Song;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Every id in a list names a song that is still in the catalog.
 *
 * Exists as a whole-array rule rather than `song_ids.*` => `Rule::exists(...)`
 * because that form runs one SELECT per element: a host opening a room with
 * their 200-track queue in it would spend 200 queries proving what one `whereIn`
 * proves. Room queues are the only place this API accepts a list of song ids,
 * and they are accepted at exactly the size where the difference matters.
 *
 * Soft-deleted songs are treated as missing (`Song` uses SoftDeletes), matching
 * every other song id check in the app.
 */
final class SongsExist implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $ids = array_values(array_unique(array_filter($value, 'is_string')));

        if ($ids === []) {
            return;
        }

        $known = Song::query()->whereIn('id', $ids)->pluck('id')->all();

        $missing = array_diff($ids, $known);

        if ($missing !== []) {
            /*
             | The count, not the ids. A client that sent an id the catalog does
             | not have is either out of date or tampering, and echoing the
             | rejected values back turns this endpoint into a way to test which
             | song ids exist.
             */
            $fail("{$attribute} contains ".count($missing).' song(s) that are not in the catalog.');
        }
    }
}
