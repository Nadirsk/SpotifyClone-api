<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListeningRoomQueueItem extends Model
{
    use HasUuids;

    /**
     * The table is named for the thing it holds rather than for this class, so
     * Eloquent's `listening_room_queue_items` guess has to be corrected.
     *
     * @var string
     */
    protected $table = 'listening_room_queue';

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'song_id',
        'added_by',
        'queue_position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'queue_position' => 'integer',
        ];
    }

    /** @return BelongsTo<ListeningRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ListeningRoom::class, 'room_id');
    }

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
