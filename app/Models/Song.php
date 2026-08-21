<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditRole;
use App\Observers\SongObserver;
use App\Services\Sync\CreditWriter;
use Database\Factories\SongFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

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

    /**
     * Songs an artist is credited on, in any music role.
     *
     * The one definition of "this artist's songs", so the artist page's
     * paginated list and its Popular shelf cannot drift apart — they only agreed
     * before because both were the same incomplete `where artist_id`.
     *
     * ## Why this does not also check `songs.artist_id`
     *
     * Because it does not have to: `song_credits` is maintained as a complete
     * superset of that column. Every song gets a `primary` credit for its
     * display artist when it is written ({@see SongObserver}) and
     * whenever its credit list is replaced
     * ({@see CreditWriter}), so an artist's own labelled
     * songs are always in here too.
     *
     * The obvious alternative — `artist_id = ? OR EXISTS (credits)` — was
     * written first and measured: it can use neither index, so MySQL scans all
     * 36,000 songs and evaluates the subquery per row. 406ms for the paginator's
     * count and 672ms for the page. This form is an index lookup on
     * `(artist_id, role)` followed by a primary-key join.
     *
     * EXISTS rather than a join, so a song credited twice (singer *and*
     * composer) appears once and the paginator's count stays correct.
     *
     * Actor credits are excluded — see {@see CreditRole::isMusicCredit()}.
     *
     * @param  Builder<Song>  $query
     * @return Builder<Song>
     */
    public function scopeCreditedTo(Builder $query, string $artistId): Builder
    {
        return $query->whereExists(function (QueryBuilder $credited) use ($query, $artistId): void {
            $credited
                ->from('song_credits')
                ->whereColumn('song_credits.song_id', $query->qualifyColumn('id'))
                ->where('song_credits.artist_id', $artistId)
                ->whereIn('song_credits.role', CreditRole::musicCreditValues());
        });
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
            ->using(PlaylistTrack::class)
            ->withPivot(['position', 'added_at']);
    }

    /** @return HasMany<ProviderSongMapping, $this> */
    public function providerMappings(): HasMany
    {
        return $this->hasMany(ProviderSongMapping::class);
    }

    /**
     * Everyone the provider credits on this recording, `artist_id` included.
     *
     * Ordered by role weight so a credits block reads top-down the way one is
     * printed — artist, singer, featured, composer, lyricist, cast — rather
     * than in whatever order the rows were inserted.
     *
     * @return HasMany<SongCredit, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany(SongCredit::class)
            ->orderByRaw(SongCredit::roleWeightOrdering())
            ->orderBy('position');
    }
}
