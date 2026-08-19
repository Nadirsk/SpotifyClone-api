<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ArtistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'follower_count',
        'is_verified',
        'dominant_type',
        'dominant_language',
        'birth_date',
        'facebook_url',
        'twitter_url',
        'wiki_url',
        'available_languages',
        'trending_score',
        'last_synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'popularity' => 'integer',
            'follower_count' => 'integer',
            'is_verified' => 'boolean',
            'available_languages' => 'array',
            'trending_score' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<Album, $this> */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    /**
     * Songs whose *display* artist is this one.
     *
     * Narrower than the artist's actual discography, and deliberately so: this
     * is the right relation for anything that means "rows labelled with this
     * name". For everything they are credited on — as singer, composer,
     * lyricist or guest — use {@see Song::scopeCreditedTo()}.
     *
     * @return HasMany<Song, $this>
     */
    public function songs(): HasMany
    {
        return $this->hasMany(Song::class);
    }

    /**
     * The listeners following this artist.
     *
     * Added for `NotifyFollowersOfRelease`'s fan-out — the inverse direction
     * (`User`'s followed artists) already existed via `ArtistFollowRepository`,
     * but announcing a release needs to walk it this way round.
     *
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'artist_follows')->withTimestamps();
    }

    /** @return HasMany<ProviderArtistMapping, $this> */
    public function providerMappings(): HasMany
    {
        return $this->hasMany(ProviderArtistMapping::class);
    }
}
