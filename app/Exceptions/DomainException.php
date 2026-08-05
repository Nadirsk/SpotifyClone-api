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
}
