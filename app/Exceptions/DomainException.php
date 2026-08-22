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

    /*
    |--------------------------------------------------------------------------
    | Device sessions
    |--------------------------------------------------------------------------
    */

    /**
     * Worded without confirming whether the id exists at all: this is also the
     * answer when a caller names a session belonging to somebody else, and
     * "that session is not yours" would tell them they had guessed a real one.
     */
    public static function deviceSessionNotFound(): self
    {
        return new self(404, 'That device is not signed in to this account.');
    }

    /**
     * 422 rather than 401: the ticket is not a credential the caller can fix by
     * re-presenting it, and a 401 would trip the frontend's interceptor into
     * clearing a token this caller does not have. The remedy is to start the
     * sign-in over, which is what the message says.
     */
    public static function deviceSessionTicketExpired(): self
    {
        return new self(422, 'This sign-in attempt has expired. Please sign in again.');
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

    /*
    |--------------------------------------------------------------------------
    | Listen Together
    |--------------------------------------------------------------------------
    */

    public static function listeningRoomNotFound(): self
    {
        return new self(404, 'No listening room exists with that code.');
    }

    /**
     * 410 rather than 404, and the distinction is the whole reason rooms are
     * closed instead of deleted: whoever clicked the link needs to know if they
     * mistyped the code or simply arrived after everyone went home. Those are
     * different problems with different next steps, and one status for both
     * sends someone to check their spelling for ten minutes.
     */
    public static function listeningRoomEnded(): self
    {
        return new self(410, 'This listening room has ended.');
    }

    public static function listeningRoomFull(int $max): self
    {
        return new self(422, "This listening room is full ({$max} listeners).");
    }

    public static function listeningQueueFull(int $max): self
    {
        return new self(422, "A listening room queue can hold at most {$max} songs.");
    }

    public static function listeningQueueItemNotFound(): self
    {
        return new self(404, 'That song is not in this room queue.');
    }

    /**
     * A room whose code could not be minted. Reachable only by losing the
     * collision race repeatedly, which at six characters of a 32-symbol
     * alphabet means the table is holding an implausible number of live rooms —
     * so this is a capacity signal rather than a validation failure, and 503
     * says "try again" instead of "you did it wrong".
     */
    public static function listeningRoomCodeUnavailable(): self
    {
        return new self(503, 'Could not create a listening room right now. Please try again.');
    }

    /*
    |--------------------------------------------------------------------------
    | Blend
    |--------------------------------------------------------------------------
    */

    public static function blendFull(int $max): self
    {
        return new self(422, "A Blend can have at most {$max} members.");
    }

    public static function alreadyBlendMember(): self
    {
        return new self(409, 'This person is already in the Blend.');
    }

    public static function cannotInviteSelfToBlend(): self
    {
        return new self(422, 'You cannot invite yourself to your own Blend.');
    }

    public static function blendInvitationInvalid(): self
    {
        return new self(404, 'This invitation is invalid, expired, or has already been used.');
    }

    /**
     * 403, not 404: the token is real and resolves to a real Blend, but the
     * signed-in account is not who it was addressed to. Distinguishing this
     * from "invalid token" is what 12_SCOPE_OF_WORK §23 means by "never trust
     * blend_id from the frontend without authorization" applied to invitations.
     */
    public static function blendInvitationNotForYou(): self
    {
        return new self(403, 'This invitation was not sent to your account.');
    }

    public static function notABlendMember(): self
    {
        return new self(404, 'This user is not a member of this Blend.');
    }

    public static function cannotRemoveBlendCreator(): self
    {
        return new self(422, "The creator can't be removed from their own Blend — delete it instead.");
    }

    /**
     * The creator cannot "leave" — deleting the Blend is the only equivalent
     * action, same distinction as `PlaylistPolicy::leave`.
     */
    public static function blendCreatorCannotLeave(): self
    {
        return new self(422, 'As the creator, you can delete this Blend but cannot leave it.');
    }

    /**
     * Thrown when a Blend has fewer than two active members — nothing to
     * combine yet, so BlendGenerationService has nothing to do.
     */
    public static function blendNotYetActive(): self
    {
        return new self(422, 'This Blend needs at least one more member before it can be generated.');
    }
}
