<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Credentials were accepted, but the account already holds as many live device
 * sessions as its plan allows.
 *
 * 409, not 403: nothing is wrong with the request or the credentials — the
 * server is in a state the caller can resolve, by signing one of the listed
 * devices out. That is the whole point of the flow, so this exception carries
 * the device list and a single-use ticket for acting on it rather than being a
 * dead end. {@see ApiExceptionRenderer} attaches `payload()` to the error
 * envelope under `session_limit`, and `POST /auth/sessions/resolve` consumes the
 * ticket.
 *
 * Separate from {@see DomainException} for exactly that reason: every named
 * constructor there is status-plus-message, and this one needs a body. Bolting
 * an optional payload onto DomainException would put a nullable array on twelve
 * error types that will never use it.
 *
 * ## Why a ticket rather than "log in again after signing out"
 *
 * The caller is not authenticated at this point — that is what they were trying
 * to become — so they hold no bearer token and cannot call the authenticated
 * device-management routes. The alternatives were both worse: making the
 * revoke route public would let anyone sign out anyone else's devices given a
 * session id, and silently evicting the oldest device would take the choice
 * away from the person who owns both devices. The ticket is proof that this
 * caller passed a credential check seconds ago, it is single-use, short-lived,
 * and it authorises nothing except revoking a session belonging to the one
 * account it was minted for.
 */
final class SessionLimitReachedException extends HttpException
{
    /**
     * @param  array<string, mixed>  $payload  The `session_limit` block: the cap,
     *                                         the plan it came from, the live
     *                                         devices, and the resolution ticket.
     */
    public function __construct(
        string $message,
        private readonly array $payload,
    ) {
        parent::__construct(409, $message);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
