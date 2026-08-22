<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlendReason;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The `blends.songs()` pivot — mirrors `PlaylistTrack`, including extending
 * `Pivot` (not `Model`) so `Blend::songs()`'s `->using()` hydrates a typed
 * pivot with real casts instead of Eloquent's generic, uncast `Pivot`.
 */
class BlendSong extends Pivot
{
    use HasUuids;

    public $timestamps = true;

    /** @var list<string> */
    protected $fillable = [
        'blend_id',
        'song_id',
        'score',
        'reason',
        'attributed_user_id',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'reason' => BlendReason::class,
            'position' => 'integer',
        ];
    }
}
