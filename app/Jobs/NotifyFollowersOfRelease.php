<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Album;
use App\Models\Artist;
use App\Models\User;
use App\Notifications\NewReleaseFromArtist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells everyone following an artist that a new album of theirs has appeared.
 *
 * Queued — unlike the notification classes themselves, which write inline (see
 * `RendersInAppPayload`). The distinction is fan-out: this can be thousands of
 * inserts for a popular artist, and it is triggered from inside a sync run that
 * a user request may be waiting on. One notification is an INSERT; a hundred
 * thousand is a background job.
 *
 * Dispatched by `SyncService::syncAlbum()` only for an album the catalog had
 * never seen. Re-syncing an existing album must never re-announce it, which is
 * why the caller gates on `wasRecentlyCreated` rather than this job trying to
 * work out whether the release is "new".
 */
final class NotifyFollowersOfRelease implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Followers notified per batch — keeps a viral artist off one huge insert. */
    private const CHUNK = 500;

    public function __construct(
        private readonly Artist $artist,
        private readonly Album $album,
    ) {}

    public function handle(): void
    {
        /*
         | Re-read rather than trusting the serialised models: this job can run
         | minutes after dispatch, and an album deleted or an artist merged by
         | the deduplicator in the meantime must not produce a notification
         | pointing at a row that no longer exists.
         */
        if (! Album::query()->whereKey($this->album->getKey())->exists()) {
            return;
        }

        $this->artist->followers()
            ->chunkById(self::CHUNK, function (EloquentCollection $followers): void {
                /** @var EloquentCollection<int, User> $followers */
                Notification::send($followers, new NewReleaseFromArtist($this->artist, $this->album));
            });
    }
}
