<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ArtistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Artist extends Model
{
    /** @use HasFactory<ArtistFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'bio',
        'image',
        'country',
        'popularity',
        'trending_score',
        'last_synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'popularity' => 'integer',
            'trending_score' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<Album, $this> */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    /** @return HasMany<Song, $this> */
    public function songs(): HasMany
    {
        return $this->hasMany(Song::class);
    }

    /** @return HasMany<ProviderArtistMapping, $this> */
    public function providerMappings(): HasMany
    {
        return $this->hasMany(ProviderArtistMapping::class);
    }
}
