<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum ConfigApply: string
{
    case Dynamic = 'dynamic';
    case Restart = 'restart';
}
