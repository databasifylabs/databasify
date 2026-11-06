<?php

declare(strict_types=1);

namespace App\Enums\Servers;

enum OsFamily: string
{
    case Debian = 'debian';
    case Rhel = 'rhel';
}
