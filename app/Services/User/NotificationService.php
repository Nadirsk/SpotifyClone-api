<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app inbox, over Laravel's own `notifications` table.
 *
 * Thin by design — the framework already owns writing, reading and marking, so
 * this exists to give the controller a vocabulary in this app's terms
 * (`unreadCount`, `markAllRead`) and to keep `DatabaseNotification` out of the
 * controller. Anything that *sends* a notification calls `$user->notify()`
 * directly at the point the thing happened; centralising sends here would just
 * add a hop.
 */
final class NotificationService
{
    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    public function paginate(User $user, int $page, int $limit, bool $unreadOnly = false): LengthAwarePaginator
    {
        $query = $user->notifications()->getQuery();

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        /** @var LengthAwarePaginator<int, DatabaseNotification> */
        return $query->paginate(perPage: $limit, page: $page);
    }

    /** What the bell badge shows. */
    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * Mark one as read.
     *
     * Returns false rather than throwing for an id that is not this user's:
     * the caller turns that into a 404, and scoping the lookup to `$user`
     * means another account's notification is indistinguishable from a
     * nonexistent one — which is the correct thing to leak, i.e. nothing.
     */
    public function markRead(User $user, string $id): bool
    {
        $notification = $user->notifications()->whereKey($id)->first();

        if ($notification === null) {
            return false;
        }

        $notification->markAsRead();

        return true;
    }

    /** @return int How many were still unread. */
    public function markAllRead(User $user): int
    {
        $count = $this->unreadCount($user);

        $user->unreadNotifications->markAsRead();

        return $count;
    }

    public function delete(User $user, string $id): bool
    {
        return $user->notifications()->whereKey($id)->delete() > 0;
    }

    public function clear(User $user): int
    {
        return $user->notifications()->delete();
    }
}
