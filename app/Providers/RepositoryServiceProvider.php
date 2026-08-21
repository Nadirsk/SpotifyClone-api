<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Repositories\AlbumRepository;
use App\Contracts\Repositories\ArtistFollowRepository;
use App\Contracts\Repositories\ArtistRepository;
use App\Contracts\Repositories\ConcertRepository;
use App\Contracts\Repositories\FavoriteRepository;
use App\Contracts\Repositories\HistoryRepository;
use App\Contracts\Repositories\ListeningRoomRepository;
use App\Contracts\Repositories\PlaylistRepository;
use App\Contracts\Repositories\SearchHistoryRepository;
use App\Contracts\Repositories\SongRepository;
use App\Contracts\Repositories\UserFollowRepository;
use App\Contracts\Repositories\UserRepository;
use App\Repositories\EloquentAlbumRepository;
use App\Repositories\EloquentArtistFollowRepository;
use App\Repositories\EloquentArtistRepository;
use App\Repositories\EloquentConcertRepository;
use App\Repositories\EloquentFavoriteRepository;
use App\Repositories\EloquentHistoryRepository;
use App\Repositories\EloquentListeningRoomRepository;
use App\Repositories\EloquentPlaylistRepository;
use App\Repositories\EloquentSearchHistoryRepository;
use App\Repositories\EloquentSongRepository;
use App\Repositories\EloquentUserFollowRepository;
use App\Repositories\EloquentUserRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Services depend on the interfaces in App\Contracts\Repositories; this is the
 * only place that names a concrete implementation.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const BINDINGS = [
        SongRepository::class => EloquentSongRepository::class,
        ArtistRepository::class => EloquentArtistRepository::class,
        AlbumRepository::class => EloquentAlbumRepository::class,
        PlaylistRepository::class => EloquentPlaylistRepository::class,
        FavoriteRepository::class => EloquentFavoriteRepository::class,
        ArtistFollowRepository::class => EloquentArtistFollowRepository::class,
        ConcertRepository::class => EloquentConcertRepository::class,
        HistoryRepository::class => EloquentHistoryRepository::class,
        UserRepository::class => EloquentUserRepository::class,
        UserFollowRepository::class => EloquentUserFollowRepository::class,
        SearchHistoryRepository::class => EloquentSearchHistoryRepository::class,
        ListeningRoomRepository::class => EloquentListeningRoomRepository::class,
    ];

    public function register(): void
    {
        foreach (self::BINDINGS as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }
}
