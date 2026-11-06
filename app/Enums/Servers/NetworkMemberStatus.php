<?php

declare(strict_types=1);

namespace App\Enums\Servers;

enum NetworkMemberStatus: string
{
    case Joining = 'joining';
    case Connected = 'connected';
    case Degraded = 'degraded';
    case OutOfSync = 'out_of_sync';
    case Unreachable = 'unreachable';
}
