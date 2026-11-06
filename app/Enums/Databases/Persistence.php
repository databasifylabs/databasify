<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum Persistence: string
{
    case Snapshots = 'snapshots';
    case Off = 'off';
}
