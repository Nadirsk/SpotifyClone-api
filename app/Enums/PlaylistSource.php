<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which system owns a playlist row.
 *
 * The distinction matters at write time, not read time. A provider playlist is
 * rebuilt wholesale from the provider on every refresh, so the sync is allowed
 * to truncate and replace its tracks; doing that to a user's playlist would
 * silently delete their work. Every destructive playlist write scopes itself
 * to one source for that reason.
 */
enum PlaylistSource: string
{
    /** Built by a person in the app. Never touched by sync. */
    case User = 'user';

    /** Editorial playlist mirrored from JioSaavn. Owned by no user. */
    case JioSaavn = 'jiosaavn';

    /** Whether sync may rewrite this playlist's tracks. */
    public function isProviderOwned(): bool
    {
        return $this !== self::User;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
