<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\SongObserver;
use Database\Factories\SongFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(SongObserver::class)]
class Song extends Model
{
    /** @use HasFactory<SongFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'artist_id',
        'album_id',
        'genre_id',
        'language_id',
        'title',
        // Parsed out of `title` by SoundtrackParser, not supplied by any
        // provider. Null for everything that is not film music.
        'film_title',
        'slug',
        'track_number',
        'duration',
        'isrc',
        'release_date',
        'popularity',
        'trending_score',
        'play_count',
        'preview_url',
        'external_url',
        'label',
        'copyright',
        'is_explicit',
        'has_lyrics',
        'last_synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'track_number' => 'integer',
            'duration' => 'integer',
            'release_date' => 'date',
            'popularity' => 'integer',
            'trending_score' => 'integer',
            'play_count' => 'integer',
            'is_explicit' => 'boolean',
            'has_lyrics' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<Genre, $this> */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** @return HasMany<Favorite, $this> */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /** @return BelongsToMany<Playlist, $this> */
    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'playlist_tracks')
            ->withPivot(['position', 'added_at']);
    }

    /** @return HasMany<ProviderSongMapping, $this> */
    public function providerMappings(): HasMany
    {
        return $this->hasMany(ProviderSongMapping::class);
    }
}
