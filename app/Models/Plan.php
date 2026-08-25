<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a subscription tier costs and unlocks — see the `plans` migration for
 * why this has exactly four rows, keyed by `App\Enums\SubscriptionPlan`'s own
 * string values, and why nothing creates or deletes one.
 */
class Plan extends Model
{
    /** @var string */
    protected $table = 'plans';

    /** @var string */
    protected $primaryKey = 'plan';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /**
     * `plan` is included so the seeder can set it via `updateOrCreate()` —
     * but no public or admin request ever puts `plan` in its validated
     * payload (the row is always addressed by the `{plan}` route segment,
     * never the body), so mass assignment never actually reassigns it
     * outside the seeder.
     *
     * @var list<string>
     */
    protected $fillable = [
        'plan',
        'name',
        'tagline',
        'accounts',
        'max_sessions',
        'reference_price_inr',
        'reference_price_usd',
        'entitlements',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'accounts' => 'integer',
            'max_sessions' => 'integer',
            'reference_price_inr' => 'integer',
            'reference_price_usd' => 'integer',
            'entitlements' => 'array',
        ];
    }
}
