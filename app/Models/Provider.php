<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'api_name',
        'enabled',
        'priority',
        'last_synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'priority' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Provider>  $query
     * @return Builder<Provider>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /** @return HasMany<ProviderSongMapping, $this> */
    public function songMappings(): HasMany
    {
        return $this->hasMany(ProviderSongMapping::class);
    }

    /** @return HasMany<ProviderArtistMapping, $this> */
    public function artistMappings(): HasMany
    {
        return $this->hasMany(ProviderArtistMapping::class);
    }

    /** @return HasMany<ProviderAlbumMapping, $this> */
    public function albumMappings(): HasMany
    {
        return $this->hasMany(ProviderAlbumMapping::class);
    }
}
