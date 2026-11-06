<?php

declare(strict_types=1);

namespace App\Enums\Queues;

enum QueueGroup: string
{
    case Default = 'default';
    case Monitoring = 'monitoring';
    case Provisioning = 'provisioning';
    case Databases = 'databases';
    case Migrations = 'migrations';
    case Terminals = 'terminals';
}
