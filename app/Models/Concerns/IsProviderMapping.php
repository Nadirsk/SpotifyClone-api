<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared behaviour for the three provider mapping tables, which differ only in
 * which local entity and external ID column they carry.
 */
trait IsProviderMapping
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
