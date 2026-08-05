<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued so that a slow or failing mail transport can never delay — or fail —
 * the registration request that triggered it.
 *
 * Logging only for now: the welcome email templates are out of scope, and the
 * queue plumbing is worth having in place before they land.
 */
final class SendWelcomeNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public function handle(UserRegistered $event): void
    {
        Log::info('Welcome notification queued for new user.', [
            'user_id' => $event->user->getKey(),
        ]);
    }
}
