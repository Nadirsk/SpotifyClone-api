<?php

declare(strict_types=1);

namespace App\Enums;

enum BlendMemberRole: string
{
    case Creator = 'creator';
    case Member = 'member';
}
