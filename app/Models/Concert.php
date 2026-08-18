<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConcertFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Concert extends Model
{
    /** @use HasFactory<ConcertFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'venue_id',
        'title',
        'date',
        'date_label',
        'event_count',
        'genres',
        'vendors',
        'image',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'genres' => 'array',
            'vendors' => 'array',
        ];
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
