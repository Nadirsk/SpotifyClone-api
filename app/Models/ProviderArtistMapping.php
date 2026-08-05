<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsProviderMapping;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderArtistMapping extends Model
{
    use HasUuids, IsProviderMapping;

    /** @var list<string> */
    protected $fillable = [
        'artist_id',
        'provider_id',
        'provider_artist_id',
        'checksum',
        'last_synced_at',
    ];

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
