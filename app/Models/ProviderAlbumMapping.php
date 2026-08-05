<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsProviderMapping;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderAlbumMapping extends Model
{
    use HasUuids, IsProviderMapping;

    /** @var list<string> */
    protected $fillable = [
        'album_id',
        'provider_id',
        'provider_album_id',
        'checksum',
        'last_synced_at',
    ];

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }
}
