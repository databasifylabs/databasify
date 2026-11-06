<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum PoolMode: string
{
    case Transaction = 'transaction';
    case Session = 'session';
    case Statement = 'statement';
}
