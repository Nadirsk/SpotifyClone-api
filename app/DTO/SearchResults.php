<?php

declare(strict_types=1);

namespace App\DTO;

use App\Contracts\Search\SearchEngine;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Playlist;
use App\Models\Song;
use Illuminate\Support\Collection;

/**
 * Result of a global (all-types) search.
 *
 * @see SearchEngine::searchAll()
 */
final readonly class SearchResults
{
    /**
     * @param  Collection<int, Song>  $songs
     * @param  Collection<int, Artist>  $artists
     * @param  Collection<int, Album>  $albums
     * @param  Collection<int, Playlist>  $playlists
     */
    public function __construct(
        public Collection $songs,
        public Collection $artists,
        public Collection $albums,
        public Collection $playlists,
    ) {}

    public static function empty(): self
    {
        return new self(
            songs: new Collection,
            artists: new Collection,
            albums: new Collection,
            playlists: new Collection,
        );
    }

    public function total(): int
    {
        return $this->songs->count()
            + $this->artists->count()
            + $this->albums->count()
            + $this->playlists->count();
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }
}
