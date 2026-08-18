<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsProviderMapping;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderPlaylistMapping extends Model
{
    use HasUuids, IsProviderMapping;

    /** @var list<string> */
    protected $fillable = [
        'playlist_id',
        'provider_id',
        'provider_playlist_id',
        'checksum',
        'last_synced_at',
    ];

    /** @return BelongsTo<Playlist, $this> */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
