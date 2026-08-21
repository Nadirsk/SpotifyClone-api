<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Listening\ListeningRoomService;
use Carbon\CarbonInterface;
use Database\Factories\ListeningRoomFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Listen Together room, and the authoritative answer to "what is playing here".
 *
 * @see ListeningRoomService for how the state moves.
 */
class ListeningRoom extends Model
{
    /** @use HasFactory<ListeningRoomFactory> */
    use HasFactory, HasUuids;

    /**
     * Milliseconds are kept when this model writes a date.
     *
     * Eloquent formats dates with the connection's format, which is
     * `Y-m-d H:i:s` — so without this, every write of `position_at` would be
     * truncated to the second and the datetime(3) column it lands in would be
     * decorative. A second of error is four times the drift the clients bother
     * to correct, so this is the difference between the feature working and
     * appearing to.
     *
     * `created_at` and `updated_at` are plain second-precision columns and are
     * written through this format too; MySQL rounds the fraction away on the way
     * in, which costs nothing. Reads are unaffected either way: Eloquent falls
     * back to a lenient parse when a stored value does not match this format.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @var list<string> */
    protected $fillable = [
        'room_code',
        'host_user_id',
        'current_song_id',
        'position_ms',
        'is_playing',
        'position_at',
        'playback_version',
        'ended_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position_ms' => 'integer',
            'is_playing' => 'boolean',
            /*
             | The column carries milliseconds (see the migration), and Carbon
             | keeps them through this cast. Anything that formats this value for
             | a client must use the millisecond epoch rather than an ISO string
             | truncated to the second, or the precision the column exists for is
             | thrown away at the boundary.
             */
            'position_at' => 'datetime',
            'playback_version' => 'integer',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /** @return BelongsTo<Song, $this> */
    public function currentSong(): BelongsTo
    {
        return $this->belongsTo(Song::class, 'current_song_id');
    }

    /** @return HasMany<ListeningRoomMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ListeningRoomMember::class, 'room_id');
    }

    /** @return HasMany<ListeningRoomQueueItem, $this> */
    public function queue(): HasMany
    {
        return $this->hasMany(ListeningRoomQueueItem::class, 'room_id')->orderBy('queue_position');
    }

    public function isLive(): bool
    {
        return $this->ended_at === null;
    }

    public function isHost(?User $user): bool
    {
        return $user !== null && $user->getKey() === $this->host_user_id;
    }

    /**
     * Where this room's current song has reached, at the given instant.
     *
     * This is the one piece of arithmetic the whole feature rests on. A stored
     * position is only meaningful together with the instant it was measured: a
     * room that has been playing for forty seconds still holds the position the
     * host pressed play at, and handing that number to a listener joining now
     * would put them forty seconds behind everybody else.
     *
     * A paused room needs none of this — its position is simply true until
     * somebody changes it.
     *
     * Two clamps, both load-bearing:
     *
     * - **Never negative.** `$at` comes from the server clock, but `position_at`
     *   was written by an earlier request that may have been served by a machine
     *   whose clock is a few milliseconds ahead. Without the floor, a listener
     *   joining in that window is told to seek to a negative time, which browsers
     *   variously clamp, ignore or throw on.
     *
     * - **Never past the end of the song**, when the song is loaded and knows its
     *   own length. A room left playing overnight would otherwise report a
     *   position hours past the end of a three-minute track; a joiner seeking
     *   there gets `ended` immediately and sits in silence, which reads exactly
     *   like the feature being broken. `relationLoaded` rather than a plain
     *   `$this->currentSong` because lazy loading throws outside production
     *   (CONVENTIONS "Non-negotiables"), and a position is legitimately asked for
     *   in contexts that never needed the song itself.
     */
    public function positionMsAt(CarbonInterface $at): int
    {
        $position = $this->position_ms;

        if ($this->is_playing && $this->position_at !== null) {
            $elapsed = (int) ($at->getPreciseTimestamp(3) - $this->position_at->getPreciseTimestamp(3));

            $position += $elapsed;
        }

        $position = max(0, $position);

        if ($this->relationLoaded('currentSong') && $this->currentSong !== null) {
            $duration = (int) $this->currentSong->duration;

            if ($duration > 0) {
                $position = min($position, $duration * 1000);
            }
        }

        return $position;
    }

    /**
     * The channel every member of this room subscribes to.
     *
     * Keyed by id rather than by `room_code`, so a channel name never depends on
     * a value a human types. Defined here because the broadcast events, the
     * channel authorization callback and the tests must all agree on it, and
     * three string literals eventually will not.
     */
    public function channelName(): string
    {
        return 'listening-room.'.$this->getKey();
    }
}
