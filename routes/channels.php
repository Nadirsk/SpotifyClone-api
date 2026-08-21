<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Listening\ListeningRoomService;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Registered by bootstrap/app.php's withBroadcasting() call, which puts the
| authorization endpoint at `POST api/v1/broadcasting/auth` behind
| `auth:sanctum` — this API has no session for the default `web`-guarded route
| to read. See that file for the whole reason.
|
| The default `App.Models.User.{id}` channel that install:broadcasting scaffolds
| here is deliberately absent: nothing in this app broadcasts to a per-user
| channel, and its generated body compares ids with `(int)` casts, which is
| always false against this schema's UUID keys.
|
*/

/*
 | A listening room, as a presence channel.
 |
 | Presence rather than private, because the room UI has to show who is actually
 | connected — and the alternative is every client announcing itself on a timer,
 | which is the polling this feature is built to avoid.
 |
 | The callback is the subscription gate: return null and the subscription is
 | refused, so a listener who is not in this room never receives its traffic.
 | That check cannot live in the frontend, and it cannot live only in the REST
 | policy either — a channel is joinable without ever calling the API.
 |
 | Keyed by room id, not by room_code: a channel name would otherwise be derived
 | from a short string that humans read out and paste around, and subscribing is
 | not something a guessed code should be able to do.
 */
Broadcast::channel(
    'listening-room.{roomId}',
    fn (User $user, string $roomId): ?array => app(ListeningRoomService::class)
        ->channelMembership($roomId, $user),
);
