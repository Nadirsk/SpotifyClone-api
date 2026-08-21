<?php

declare(strict_types=1);

namespace App\Http\Requests\Listening;

use App\Rules\SongsExist;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The host's whole queue, in play order.
 *
 * `present` rather than `required` on the list: an empty array is a legitimate
 * body — it is what "clear the queue" looks like — and `required` rejects
 * exactly that.
 */
final class ReplaceQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Host-only is enforced by ListeningRoomPolicy from the controller.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'song_ids' => ['present', 'array', 'max:1000', new SongsExist],
            'song_ids.*' => ['uuid'],
        ];
    }

    /** @return list<string> */
    public function songIds(): array
    {
        /** @var list<string> $ids */
        $ids = array_values($this->validated('song_ids'));

        return $ids;
    }
}
