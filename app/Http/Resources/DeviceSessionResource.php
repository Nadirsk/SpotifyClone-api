<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One logged-in device.
 *
 * Deliberately thin. A device list is shown to someone deciding which session
 * to kill, and the only questions that helps answer are "what is it" and "when
 * was it last me" — so it carries the label, the two timestamps, and whether
 * this row is the reader's own session. Nothing here identifies a location or
 * an address: an IP would be one more piece of personal data stored on every
 * login to answer a question the label and `last_used_at` already answer.
 *
 * `token` (the hash) and `abilities` are never exposed. The hash is a
 * credential-equivalent and the abilities are uniform across every token this
 * app mints.
 *
 * @mixin PersonalAccessToken
 */
final class DeviceSessionResource extends JsonResource
{
    /**
     * The name every token carried before `DeviceSessionService` started
     * labelling them by device. Sessions that predate the device cap are still
     * valid credentials, so they appear in this list — but showing an internal
     * string as the device name reads as a bug, hence the substitution.
     */
    private const LEGACY_TOKEN_NAME = 'api';

    /**
     * @param  int|null  $currentId  Id of the token making this request, when
     *                               there is one. Null during the login-conflict
     *                               flow, where the caller holds no token yet —
     *                               which is why this is a parameter rather than
     *                               being read off the request here.
     */
    public function __construct(
        PersonalAccessToken $resource,
        private readonly ?int $currentId = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'device' => $this->name === self::LEGACY_TOKEN_NAME ? 'Unknown device' : (string) $this->name,
            'created_at' => $this->created_at?->toIso8601String(),
            /*
             | Null when the token has been minted but never used — the window
             | between a login and the first authenticated request. Sanctum
             | stamps this on use, not on creation.
             */
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            /*
             | Lets the UI label one row "This device" and refuse to make
             | signing yourself out look like the obvious button.
             */
            'current' => $this->currentId !== null && (int) $this->id === $this->currentId,
        ];
    }
}
