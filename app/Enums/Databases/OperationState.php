<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum OperationState: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';
}
