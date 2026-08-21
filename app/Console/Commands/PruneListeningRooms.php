<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Listening\ListeningRoomService;
use Illuminate\Console\Command;

/**
 * Closes listening rooms whose members all vanished without leaving.
 *
 * Scheduled hourly in routes/console.php. See
 * {@see ListeningRoomService::prune()} for why this is coarse rather than a
 * heartbeat.
 */
final class PruneListeningRooms extends Command
{
    /** @var string */
    protected $signature = 'listening-rooms:prune';

    /** @var string */
    protected $description = 'Close listening rooms that have been idle past their expiry';

    public function handle(ListeningRoomService $rooms): int
    {
        $closed = $rooms->prune();

        $this->info($closed === 0
            ? 'No idle listening rooms to close.'
            : "Closed {$closed} idle listening room(s).");

        return self::SUCCESS;
    }
}
