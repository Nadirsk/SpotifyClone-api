<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlbumController;
use App\Http\Controllers\Api\V1\ArtistController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\GenreController;
use App\Http\Controllers\Api\V1\HistoryController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\PlaylistController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SongController;
use App\Http\Controllers\Api\V1\TrendingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| The `api/v1` prefix, throttling and the request-id / locale / logging
| middleware are applied in bootstrap/app.php — do not repeat them here.
|
| Ordering rule: a literal segment must be registered before the `{id}`
| wildcard that would otherwise swallow it (e.g. /songs/trending).
|
*/

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    Route::get('google', [AuthController::class, 'googleRedirect']);
    Route::get('google/callback', [AuthController::class, 'googleCallback']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

// `suggest` first, so a future /search/{something} cannot shadow it.
Route::get('search/suggest', [SearchController::class, 'suggest']);
Route::get('search', [SearchController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Catalog
|--------------------------------------------------------------------------
*/

Route::prefix('songs')->group(function (): void {
    Route::get('/', [SongController::class, 'index']);
    // Must precede /{id}, otherwise "trending" is read as a song id and 404s.
    Route::get('trending', [SongController::class, 'trending']);
    Route::get('{id}', [SongController::class, 'show']);
    Route::get('{id}/related', [SongController::class, 'related']);
    Route::get('{id}/preview', [SongController::class, 'preview']);
});

Route::prefix('artists')->group(function (): void {
    Route::get('/', [ArtistController::class, 'index']);
    Route::get('{id}', [ArtistController::class, 'show']);
    Route::get('{id}/albums', [ArtistController::class, 'albums']);
    Route::get('{id}/songs', [ArtistController::class, 'songs']);
    Route::get('{id}/related', [ArtistController::class, 'related']);
});

Route::prefix('albums')->group(function (): void {
    Route::get('/', [AlbumController::class, 'index']);
    Route::get('{id}', [AlbumController::class, 'show']);
    Route::get('{id}/tracks', [AlbumController::class, 'tracks']);
});

Route::prefix('trending')->group(function (): void {
    Route::get('songs', [TrendingController::class, 'songs']);
    Route::get('artists', [TrendingController::class, 'artists']);
    Route::get('albums', [TrendingController::class, 'albums']);
});

Route::get('genres', [GenreController::class, 'index']);
Route::get('languages', [LanguageController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Playlists
|--------------------------------------------------------------------------
|
| index and show carry no auth middleware so guests can browse public
| playlists. They still resolve the caller when a bearer token is present,
| because the default guard is `sanctum` (see config/auth.php).
|
*/

Route::get('playlists', [PlaylistController::class, 'index']);
Route::get('playlists/{playlist}', [PlaylistController::class, 'show']);

Route::middleware('auth:sanctum')->prefix('playlists')->group(function (): void {
    Route::post('/', [PlaylistController::class, 'store']);
    Route::put('{playlist}', [PlaylistController::class, 'update']);
    Route::delete('{playlist}', [PlaylistController::class, 'destroy']);
    Route::post('{playlist}/songs', [PlaylistController::class, 'addSong']);
    Route::delete('{playlist}/songs/{song}', [PlaylistController::class, 'removeSong']);
});

/*
|--------------------------------------------------------------------------
| User library — always scoped to the authenticated user
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('favorites', [FavoriteController::class, 'index']);
    // The path parameter is a SONG id, not a favorite id.
    Route::post('favorites/{song}', [FavoriteController::class, 'store']);
    Route::delete('favorites/{song}', [FavoriteController::class, 'destroy']);

    Route::get('history', [HistoryController::class, 'index']);
    Route::post('history', [HistoryController::class, 'store']);
    Route::delete('history', [HistoryController::class, 'destroy']);

    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::put('profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('profile', [ProfileController::class, 'destroy']);
});
