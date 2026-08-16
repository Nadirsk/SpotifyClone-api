<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AlbumFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Album extends Model
{
    /** @use HasFactory<AlbumFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'artist_id',
        'language_id',
        'title',
        // See Song::$fillable — parsed, never provider-supplied.
        'film_title',
        'slug',
        'cover_image',
        'release_date',
        'total_tracks',
        'popularity',
        'trending_score',
        'last_synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'total_tracks' => 'integer',
            'popularity' => 'integer',
            'trending_score' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** @return HasMany<Song, $this> */
    public function songs(): HasMany
    {
        return $this->hasMany(Song::class);
    }

    /** @return HasMany<ProviderAlbumMapping, $this> */
    public function providerMappings(): HasMany
    {
        return $this->hasMany(ProviderAlbumMapping::class);
    }
}
