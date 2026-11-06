<?php

declare(strict_types=1);

namespace App\Enums\Servers;

enum ServerLogStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
