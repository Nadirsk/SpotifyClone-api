<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One purchase of a paid tier. See the migration for why this is a history
 * table rather than one mutable row per user.
 */
class Subscription extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'plan',
        'status',
        'currency',
        'amount_minor',
        'started_at',
        'current_period_end',
        'cancelled_at',
        'payment_reference',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'plan' => SubscriptionPlan::class,
            'status' => SubscriptionStatus::class,
            'amount_minor' => 'integer',
            'started_at' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this row still grants its plan's entitlements *right now*.
     *
     * Cancelled counts: the listener paid through `current_period_end` and
     * keeps Premium until then. Only `Expired`, or a period end in the past,
     * stops granting — the second check matters because nothing sweeps the
     * status column in real time, so a row can be `Active` on paper and lapsed
     * in fact between one scheduled run and the next.
     */
    public function isEntitled(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        if ($this->status === SubscriptionStatus::Expired) {
            return false;
        }

        return $this->current_period_end === null || $this->current_period_end->greaterThan($at);
    }

    /** @param  Builder<$this>  $query */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at');
    }
}
