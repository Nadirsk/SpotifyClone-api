<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsProviderMapping;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderSongMapping extends Model
{
    use HasUuids, IsProviderMapping;

    /** @var list<string> */
    protected $fillable = [
        'song_id',
        'provider_id',
        'provider_song_id',
        'checksum',
        'last_synced_at',
    ];

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }
}
