<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One artist's credit on one song.
 *
 * A real model rather than an anonymous pivot: the backfill upserts these in
 * bulk, the API serializes them, and both want the role as a {@see CreditRole}
 * instead of a raw string.
 *
 * No timestamps problem to solve here — the table has them, and `HasUuids`
 * supplies the key, so a plain `Model` is enough.
 */
class SongCredit extends Model
{
    use HasUuids;

    protected $table = 'song_credits';

    /** @var list<string> */
    protected $fillable = [
        'song_id',
        'artist_id',
        'role',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => CreditRole::class,
            'position' => 'integer',
        ];
    }

    /**
     * A raw `CASE` that sorts rows by {@see CreditRole::weight()}.
     *
     * Built from the enum rather than written out, so adding a role cannot
     * leave a sort silently treating it as position zero. The values are enum
     * cases, not user input, so interpolating them is safe — and it has to be
     * an expression rather than a bound parameter list because ORDER BY CASE
     * cannot take an array.
     */
    public static function roleWeightOrdering(string $column = 'role'): string
    {
        $whens = '';

        foreach (CreditRole::cases() as $role) {
            $whens .= sprintf(" WHEN '%s' THEN %d", $role->value, $role->weight());
        }

        return sprintf('CASE %s%s ELSE %d END', $column, $whens, count(CreditRole::cases()));
    }

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
