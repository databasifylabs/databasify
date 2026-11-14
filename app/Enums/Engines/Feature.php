<?php

declare(strict_types=1);

namespace App\Enums\Engines;

enum Feature: string
{
    case Accounts = 'accounts';
    case Archive = 'archive';
    case Migration = 'migration';
    case Pools = 'pools';
}
