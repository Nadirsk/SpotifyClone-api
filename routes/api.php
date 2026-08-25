<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AdminAlbumController;
use App\Http\Controllers\Api\V1\Admin\AdminArtistController;
use App\Http\Controllers\Api\V1\Admin\AdminBlendController;
use App\Http\Controllers\Api\V1\Admin\AdminConcertController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminGenreController;
use App\Http\Controllers\Api\V1\Admin\AdminLanguageController;
use App\Http\Controllers\Api\V1\Admin\AdminPlanController;
use App\Http\Controllers\Api\V1\Admin\AdminPlaylistController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use App\Http\Controllers\Api\V1\Admin\AdminSubscriptionController;
use App\Http\Controllers\Api\V1\Admin\AdminVenueController;
use App\Http\Controllers\Api\V1\Admin\AdminSongController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\AlbumController;
use App\Http\Controllers\Api\V1\ArtistController;
use App\Http\Controllers\Api\V1\ArtistFollowController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BlendController;
use App\Http\Controllers\Api\V1\BlendInvitationController;
use App\Http\Controllers\Api\V1\ConcertController;
use App\Http\Controllers\Api\V1\DeviceSessionController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\GenreController;
use App\Http\Controllers\Api\V1\HistoryController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\ListeningRoomController;
use App\Http\Controllers\Api\V1\ListeningRoomPlaybackController;
use App\Http\Controllers\Api\V1\ListeningRoomQueueController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\PlaylistCollaboratorController;
use App\Http\Controllers\Api\V1\PlaylistController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SongController;
use App\Http\Controllers\Api\V1\SoundtrackController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TestingSupportController;
use App\Http\Controllers\Api\V1\TrendingController;
use App\Http\Controllers\Api\V1\UserFollowController;
use App\Http\Controllers\Api\V1\VenueController;
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

    /*
     | Phone sign-up's OTP send/verify. Not in 05_API_SPECIFICATION — see the
     | phone sign-up screens' docblock. `otp-send` is far tighter than the
     | global `api` limiter (05_API_SPECIFICATION §17): that one is generous
     | enough to let an attacker spray a few hundred real SMS sends a minute,
     | and every one of those costs real money.
     */
    Route::post('otp/send', [OtpController::class, 'send'])->middleware('throttle:otp-send');
    Route::post('otp/verify', [OtpController::class, 'verify'])->middleware('throttle:otp-verify');

    // The phone flow's actual account creation — same shape as `register`
    // above, gated on OtpService::wasRecentlyVerified() instead of a password.
    Route::post('register/phone', [AuthController::class, 'registerWithPhone']);
    // Same OTP gate, but for signing an *existing* phone-native account back
    // in — see AuthService::loginWithPhone() for why this never creates one.
    Route::post('login/phone', [AuthController::class, 'loginWithPhone']);

    /*
     | Passwordless email login — the email counterpart to the phone OTP
     | routes above. `send` is deliberately as generous here as `otp-send`
     | for the same reason: every real send costs an SMTP round-trip, so the
     | route-level limiter exists to blunt a flood, while EmailLoginCodeService
     | itself enforces the tighter per-address limit.
     */
    Route::post('login/email/send-code', [AuthController::class, 'sendEmailLoginCode'])
        ->middleware('throttle:email-login-send');
    Route::post('login/email/verify-code', [AuthController::class, 'verifyEmailLoginCode'])
        ->middleware('throttle:email-login-verify');

    /*
     | Device sessions — the per-plan cap on how many machines may hold a live
     | token at once (`config/plans.php` → `max_sessions`).
     |
     | `sessions/resolve` is deliberately outside the auth group below: it is
     | the second half of a login that came back 409 because the account was
     | full, so by definition the caller has no token yet. Its credential is the
     | single-use `resolution_token` from that 409 — see
     | SessionLimitReachedException. Registered before the `{session}` route so
     | the numeric wildcard cannot swallow it.
     */
    Route::post('sessions/resolve', [AuthController::class, 'resolveSessionConflict']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('sessions', [DeviceSessionController::class, 'index']);
        // Sign out every device except this one — the "secure my account" button.
        Route::delete('sessions', [DeviceSessionController::class, 'destroyOthers']);
        Route::delete('sessions/{session}', [DeviceSessionController::class, 'destroy'])
            ->whereNumber('session');
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
    /*
     | The quality-aware successor to `preview`. Deliberately unauthenticated:
     | a guest gets the free tier's ceiling rather than a 401, which is what
     | keeps the catalog audible without an account. The caller is still
     | resolved when a token is present (default guard is `sanctum`).
     */
    Route::get('{id}/stream', [SongController::class, 'stream']);
    // Premium only, and the one route that streams provider bytes through this
    // server — see PlaybackService::download()'s docblock.
    Route::middleware('auth:sanctum')->get('{id}/download', [SongController::class, 'download']);
});

/*
|--------------------------------------------------------------------------
| Soundtracks — the film/OST browse hub
|--------------------------------------------------------------------------
|
| No source in 01–11; built on request over the `film_title` column
| SoundtrackParser derives. A film is keyed by its own (URL-encoded) name
| rather than an id — see SoundtrackService's docblock for why there is no
| `films` table behind this.
|
*/

Route::get('soundtracks', [SoundtrackController::class, 'index']);
Route::get('soundtracks/{film}', [SoundtrackController::class, 'show'])->where('film', '.*');

Route::prefix('artists')->group(function (): void {
    Route::get('/', [ArtistController::class, 'index']);
    // Must precede /{id}, otherwise "followed" is read as an artist id and 404s.
    Route::middleware('auth:sanctum')->get('followed', [ArtistFollowController::class, 'index']);
    Route::get('{id}', [ArtistController::class, 'show']);
    Route::get('{id}/albums', [ArtistController::class, 'albums']);
    Route::get('{id}/songs', [ArtistController::class, 'songs']);
    Route::get('{id}/related', [ArtistController::class, 'related']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('{id}/follow', [ArtistFollowController::class, 'store']);
        Route::delete('{id}/follow', [ArtistFollowController::class, 'destroy']);
    });
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

/*
 | Personalized, so auth-gated unlike trending — see RecommendationService's
 | docblock for why this is one endpoint (songs only) rather than three.
 */
Route::middleware('auth:sanctum')->get('recommendations', [RecommendationController::class, 'songs']);

Route::get('genres', [GenreController::class, 'index']);
Route::get('languages', [LanguageController::class, 'index']);
// Grouped so `/popular-by-country` is one request rather than one per language;
// the fan-out version tripped the guest rate limit on every page load.
Route::get('languages/popular-albums', [LanguageController::class, 'popularAlbums']);

/*
|--------------------------------------------------------------------------
| Live events — no source in 01-11 (see the concerts migration's docblock);
| built on explicit request as first-party, seeded, public read-only data.
|--------------------------------------------------------------------------
*/

Route::get('venues', [VenueController::class, 'index']);
Route::prefix('concerts')->group(function (): void {
    Route::get('/', [ConcertController::class, 'index']);
    Route::get('{id}', [ConcertController::class, 'show']);
});

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
Route::get('playlists/{playlist}/collaborators', [PlaylistCollaboratorController::class, 'index']);

/*
| The pre-login "you've been invited" preview a shared link opens to — no
| auth middleware, same reasoning as index/show above. Registered before the
| auth:sanctum group purely for readability; it cannot collide with
| `playlists/{playlist}/invitations` below since "invitations" and {token}
| occupy swapped path positions in the two routes.
*/
Route::get('playlists/invitations/{token}', [PlaylistCollaboratorController::class, 'showByToken']);

Route::middleware('auth:sanctum')->prefix('playlists')->group(function (): void {
    Route::post('/', [PlaylistController::class, 'store']);
    Route::put('{playlist}', [PlaylistController::class, 'update']);
    Route::delete('{playlist}', [PlaylistController::class, 'destroy']);
    Route::post('{playlist}/songs', [PlaylistController::class, 'addSong']);
    Route::delete('{playlist}/songs/{song}', [PlaylistController::class, 'removeSong']);

    Route::post('{playlist}/invitations', [PlaylistCollaboratorController::class, 'invite']);
    Route::get('{playlist}/invitations', [PlaylistCollaboratorController::class, 'showInvitation']);
    Route::delete('{playlist}/invitations', [PlaylistCollaboratorController::class, 'revokeInvitation']);
    Route::post('invitations/{token}/accept', [PlaylistCollaboratorController::class, 'accept']);

    Route::delete('{playlist}/collaborators/{user}', [PlaylistCollaboratorController::class, 'destroy']);
    Route::post('{playlist}/leave', [PlaylistCollaboratorController::class, 'leave']);
});

/*
|--------------------------------------------------------------------------
| Public profiles and user-to-user following
|--------------------------------------------------------------------------
|
| Not in 01-11's scope (see the user_follows migration's docblock) — built on
| explicit request, following the same public-read / auth-mutation split as
| playlists above.
|
*/

Route::prefix('users')->group(function (): void {
    Route::get('{id}', [UserFollowController::class, 'show']);
    Route::get('{id}/followers', [UserFollowController::class, 'followers']);
    Route::get('{id}/following', [UserFollowController::class, 'following']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('{id}/follow', [UserFollowController::class, 'store']);
        Route::delete('{id}/follow', [UserFollowController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| Blend — combines two or more users' music taste into a shared, generated
| playlist
|--------------------------------------------------------------------------
|
| No source in 01-11 — built on explicit request, following the same
| private-by-default, invite-based pattern as Listen Together. Unlike
| playlists, EVERY route below requires auth:sanctum — a Blend has no
| public/unlisted tier to fall back to for a guest, so there is no bare
| GET /blends or GET /blends/{blend} outside the auth group.
|
| The one exception is the pre-login invitation preview, registered first for
| the same reason `playlists/invitations/{token}` is: it must not be
| swallowed by `blends/{blend}` once that wildcard is registered below.
|
*/

Route::get('blends/invitations/{token}', [BlendInvitationController::class, 'showByToken']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('blends', [BlendController::class, 'index']);
    Route::post('blends', [BlendController::class, 'store']);

    Route::post('blends/invitations/{token}/accept', [BlendInvitationController::class, 'accept']);
    Route::post('blends/invitations/{token}/decline', [BlendInvitationController::class, 'decline']);

    Route::prefix('blends')->group(function (): void {
        Route::get('{blend}', [BlendController::class, 'show']);
        Route::put('{blend}', [BlendController::class, 'update']);
        Route::delete('{blend}', [BlendController::class, 'destroy']);

        // Manual "Refresh Blend" — see BlendController::refresh's own doc for
        // why this one carries an inline throttle.
        Route::post('{blend}/refresh', [BlendController::class, 'refresh'])->middleware('throttle:2,60');

        Route::post('{blend}/save', [BlendController::class, 'save']);
        Route::post('{blend}/leave', [BlendController::class, 'leave']);
        Route::delete('{blend}/members/{user}', [BlendController::class, 'removeMember']);

        Route::get('{blend}/invitations', [BlendInvitationController::class, 'index']);
        Route::post('{blend}/invitations', [BlendInvitationController::class, 'store']);
        Route::delete('{blend}/invitations/{invitation}', [BlendInvitationController::class, 'revoke']);
    });
});

/*
|--------------------------------------------------------------------------
| Plans and subscriptions
|--------------------------------------------------------------------------
|
| `plans` is public — a signed-out visitor has to be able to see what Premium
| costs before creating an account. Everything that touches an actual
| subscription is token-scoped; there is no addressable subscription belonging
| to anyone but the caller.
|
*/

Route::get('plans', [PlanController::class, 'index']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::post('subscription', [SubscriptionController::class, 'store']);
    Route::delete('subscription', [SubscriptionController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| Ordering rule again: `unread-count` and `read-all` are literal segments that
| would otherwise be swallowed by the `{id}` routes below them.
|
*/

Route::middleware('auth:sanctum')->prefix('notifications')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/', [NotificationController::class, 'clear']);
    Route::post('{id}/read', [NotificationController::class, 'markRead']);
    Route::delete('{id}', [NotificationController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| User library — always scoped to the authenticated user
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Test-only fixtures
|--------------------------------------------------------------------------
|
| Registered only outside production. The browser E2E suite needs to force an
| account back onto the free tier between specs, which no real endpoint can do
| — cancelling deliberately preserves entitlements to the end of the paid
| period. See TestingSupportController for the full reasoning.
|
*/

if (! app()->environment('production')) {
    Route::prefix('testing')->group(function (): void {
        Route::middleware('auth:sanctum')->post(
            'reset-subscription',
            [TestingSupportController::class, 'resetSubscription'],
        );

        /*
         | Unauthenticated of necessity — it runs before a token exists — and
         | narrowed to the configured OTP-bypass number, which it refuses to
         | deviate from. It exists so the browser suite can sign in by phone
         | without `OtpService::send()` billing a real SMS on every run. See
         | TestingSupportController::verifyBypassPhone().
         */
        Route::post('verify-bypass-phone', [TestingSupportController::class, 'verifyBypassPhone']);
    });
}

/*
 | Recording a play is deliberately outside the auth group below: a signed-out
 | listener can play the catalog, so their plays have to count too, or every
 | chart derived from `history` measures only subscribers while claiming to
 | measure listening. The caller is still resolved when a token is present
 | (default guard is `sanctum`), and a guest is identified by the opaque
 | `session_id` in the body — see the add_anonymous_play_tracking migration.
 |
 | Reading and clearing history stay authenticated: those are a person's own
 | feed, and a session id is not an authentication factor.
 */
Route::post('history', [HistoryController::class, 'store']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('favorites', [FavoriteController::class, 'index']);
    // The path parameter is a SONG id, not a favorite id.
    Route::post('favorites/{song}', [FavoriteController::class, 'store']);
    Route::delete('favorites/{song}', [FavoriteController::class, 'destroy']);

    Route::get('history', [HistoryController::class, 'index']);
    Route::delete('history', [HistoryController::class, 'destroy']);

    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::put('profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('profile', [ProfileController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Listen Together
|--------------------------------------------------------------------------
|
| Rooms are addressed by their shareable code rather than by id, so the URL a
| listener is looking at and the URL the API is called with carry the same
| string. Codes are matched case-insensitively (ListeningRoomService).
|
| Everything here is authenticated: playback state is not public, and a room is
| membership even to look at. `preview` is the one endpoint a non-member may
| call, because it is what an invite link opens to before joining.
|
| The host-only writes live in their own two controllers so the authorization
| split is visible here rather than buried in method bodies. Ordering rule as
| everywhere else in this file: literal segments before the wildcard that would
| otherwise swallow them.
|
| There is no endpoint for "what is playing right now" beyond GET {code}: that
| response IS the authoritative state, and it is what a client resyncs against
| after a dropped connection. Everything else arrives over the room channel.
|
*/

Route::middleware('auth:sanctum')->prefix('listening-rooms')->group(function (): void {
    Route::post('/', [ListeningRoomController::class, 'store']);

    Route::get('{code}/preview', [ListeningRoomController::class, 'preview']);
    Route::get('{code}', [ListeningRoomController::class, 'show']);

    Route::post('{code}/join', [ListeningRoomController::class, 'join']);
    Route::post('{code}/leave', [ListeningRoomController::class, 'leave']);

    Route::post('{code}/playback', [ListeningRoomPlaybackController::class, 'update']);

    Route::put('{code}/queue', [ListeningRoomQueueController::class, 'replace']);
    Route::post('{code}/queue', [ListeningRoomQueueController::class, 'store']);
    Route::delete('{code}/queue/{item}', [ListeningRoomQueueController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
|
| Gated by `admin` on top of `auth:sanctum` (see EnsureUserIsAdmin) — a
| listener's otherwise-valid token gets a 403 here, not a 401. Admin login
| itself reuses the public POST /auth/login above; there is no separate
| admin auth endpoint.
|
| Each screen's routes are added here as they are built, per the
| screen-by-screen admin panel development plan.
|
*/

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function (): void {
    Route::prefix('songs')->group(function (): void {
        Route::get('/', [AdminSongController::class, 'index']);
        Route::post('/', [AdminSongController::class, 'store']);
        Route::get('{id}', [AdminSongController::class, 'show']);
        Route::put('{id}', [AdminSongController::class, 'update']);
        Route::delete('{id}', [AdminSongController::class, 'destroy']);
    });

    Route::prefix('users')->group(function (): void {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::get('{id}', [AdminUserController::class, 'show']);
        Route::put('{id}/role', [AdminUserController::class, 'updateRole']);
        Route::delete('{id}', [AdminUserController::class, 'destroy']);
    });

    Route::prefix('artists')->group(function (): void {
        Route::get('/', [AdminArtistController::class, 'index']);
        Route::post('/', [AdminArtistController::class, 'store']);
        Route::get('{id}', [AdminArtistController::class, 'show']);
        Route::put('{id}', [AdminArtistController::class, 'update']);
        Route::delete('{id}', [AdminArtistController::class, 'destroy']);
    });

    Route::prefix('albums')->group(function (): void {
        Route::get('/', [AdminAlbumController::class, 'index']);
        Route::post('/', [AdminAlbumController::class, 'store']);
        Route::get('{id}', [AdminAlbumController::class, 'show']);
        Route::put('{id}', [AdminAlbumController::class, 'update']);
        Route::delete('{id}', [AdminAlbumController::class, 'destroy']);
    });

    Route::prefix('genres')->group(function (): void {
        Route::get('/', [AdminGenreController::class, 'index']);
        Route::post('/', [AdminGenreController::class, 'store']);
        Route::put('{id}', [AdminGenreController::class, 'update']);
        Route::delete('{id}', [AdminGenreController::class, 'destroy']);
    });

    Route::prefix('playlists')->group(function (): void {
        Route::get('/', [AdminPlaylistController::class, 'index']);
        Route::get('{id}', [AdminPlaylistController::class, 'show']);
        Route::delete('{id}', [AdminPlaylistController::class, 'destroy']);
    });

    Route::prefix('venues')->group(function (): void {
        Route::get('/', [AdminVenueController::class, 'index']);
        Route::post('/', [AdminVenueController::class, 'store']);
        Route::put('{id}', [AdminVenueController::class, 'update']);
        Route::delete('{id}', [AdminVenueController::class, 'destroy']);
    });

    Route::prefix('concerts')->group(function (): void {
        Route::get('/', [AdminConcertController::class, 'index']);
        Route::post('/', [AdminConcertController::class, 'store']);
        Route::get('{id}', [AdminConcertController::class, 'show']);
        Route::put('{id}', [AdminConcertController::class, 'update']);
        Route::delete('{id}', [AdminConcertController::class, 'destroy']);
    });

    Route::prefix('languages')->group(function (): void {
        Route::get('/', [AdminLanguageController::class, 'index']);
        Route::post('/', [AdminLanguageController::class, 'store']);
        Route::put('{id}', [AdminLanguageController::class, 'update']);
        Route::delete('{id}', [AdminLanguageController::class, 'destroy']);
    });

    Route::prefix('blends')->group(function (): void {
        Route::get('/', [AdminBlendController::class, 'index']);
        Route::get('{id}', [AdminBlendController::class, 'show']);
        Route::delete('{id}', [AdminBlendController::class, 'destroy']);
    });

    Route::prefix('subscriptions')->group(function (): void {
        Route::get('/', [AdminSubscriptionController::class, 'index']);
        Route::put('{id}/status', [AdminSubscriptionController::class, 'updateStatus']);
    });

    Route::prefix('plans')->group(function (): void {
        Route::get('/', [AdminPlanController::class, 'index']);
        Route::post('/', [AdminPlanController::class, 'store']);
        Route::put('{plan}', [AdminPlanController::class, 'update']);
        Route::delete('entitlements/{key}', [AdminPlanController::class, 'destroyEntitlement']);
    });

    Route::prefix('dashboard')->group(function (): void {
        Route::get('summary', [AdminDashboardController::class, 'summary']);
        Route::get('revenue-trend', [AdminDashboardController::class, 'revenueTrend']);
        Route::get('trending-songs', [AdminDashboardController::class, 'trendingSongs']);
        Route::get('new-releases', [AdminDashboardController::class, 'newReleases']);
    });

    Route::get('reports', [AdminReportController::class, 'index']);
});
