<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlendInvitationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlendInvitation extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'blend_id',
        'invited_by',
        'invited_user_id',
        'token',
        'status',
        'expires_at',
        'responded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BlendInvitationStatus::class,
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Blend, $this> */
    public function blend(): BelongsTo
    {
        return $this->belongsTo(Blend::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<User, $this> */
    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    /**
     * Whether this invitation can still be responded to.
     *
     * Unlike `PlaylistInvitation::isActive()`, this also checks `status`: a
     * playlist link is reusable until revoked, but a Blend invitation is
     * addressed to one person and is spent the moment they answer it.
     */
    public function isActive(): bool
    {
        if ($this->status !== BlendInvitationStatus::Pending) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
