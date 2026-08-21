<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListeningRole;
use App\Repositories\EloquentListeningRoomRepository;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's membership of one room.
 *
 * Rows are kept after someone leaves (`left_at`), so "active member" is always
 * a filtered read rather than the mere existence of a row — see
 * {@see EloquentListeningRoomRepository::activeMembers()}.
 */
class ListeningRoomMember extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'user_id',
        'role',
        'joined_at',
        'last_seen_at',
        'left_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => ListeningRole::class,
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ListeningRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ListeningRoom::class, 'room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }
}
