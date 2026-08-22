<?php

declare(strict_types=1);

namespace App\Enums;

enum BlendInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked = 'revoked';
}
