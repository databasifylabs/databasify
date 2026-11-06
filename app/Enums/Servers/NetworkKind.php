<?php

declare(strict_types=1);

namespace App\Enums\Servers;

enum NetworkKind: string
{
    case Provider = 'provider';
    case Custom = 'custom';
    case Wireguard = 'wireguard';
}
