<?php

declare(strict_types=1);

namespace App\Enums\Updates;

enum UpdateStepState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
