<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class History extends Model
{
    use HasUuids;

    protected $table = 'history';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        // Null once there is an account; identifies a signed-out listener's
        // browser for the play dedupe window and nothing else.
        'session_id',
        'song_id',
        'played_at',
        'ms_played',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'played_at' => 'datetime',
            'ms_played' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }
}
