<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A business rule was broken — a playlist cap, a duplicate track, a missing
 * track.
 *
 * Extends Symfony's HttpException so it arrives at
 * {@see ApiExceptionRenderer} as an HttpExceptionInterface and is rendered with
 * its own status code and message in the standard error envelope. Services
 * therefore stay free of HTTP handling: they throw, and the renderer answers.
 *
 * Named constructors keep the status codes in one place instead of scattering
 * magic numbers across the services.
 */
final class DomainException extends HttpException
{
    public static function playlistLimitReached(int $max): self
    {
        return new self(422, "You have reached the limit of {$max} playlists.");
    }

    public static function playlistFull(int $max): self
    {
        return new self(422, "A playlist can hold at most {$max} songs.");
    }

    public static function songAlreadyInPlaylist(): self
    {
        return new self(409, 'This song is already in the playlist.');
    }

    public static function songNotInPlaylist(): self
    {
        return new self(404, 'This song is not in the playlist.');
    }

    public static function invitationInvalid(): self
    {
        return new self(404, 'This invite link is invalid, expired, or has been revoked.');
    }

    public static function cannotJoinOwnPlaylist(): self
    {
        return new self(422, 'You already own this playlist.');
    }

    public static function notACollaborator(): self
    {
        return new self(404, 'This user is not a collaborator on this playlist.');
    }

    /*
    |--------------------------------------------------------------------------
    | Subscriptions and entitlements
    |--------------------------------------------------------------------------
    */

    /**
     * 402 rather than 403: the caller is authenticated and the request is
     * well-formed — the only thing missing is payment. That distinction is what
     * lets the frontend show an upgrade prompt for this and a plain "not
     * allowed" for a genuine authorization failure.
     */
    public static function premiumRequired(string $capability): self
    {
        return new self(402, "This feature requires Premium ({$capability}).");
    }

    public static function alreadySubscribed(string $plan): self
    {
        return new self(409, "You are already subscribed to the {$plan} plan.");
    }

    public static function noActiveSubscription(): self
    {
        return new self(404, 'You do not have an active subscription.');
    }

    public static function planNotPurchasable(): self
    {
        return new self(422, 'That plan cannot be purchased.');
    }

    /**
     * The provider serves a fixed ladder of bitrates and this platform never
     * transcodes (11_PROVIDER_INTEGRATION), so a track with no usable source
     * URL cannot be downgraded into one.
     */
    public static function noAudioSource(): self
    {
        return new self(404, 'No playable audio source exists for this track.');
    }
}
