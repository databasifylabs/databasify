<?php

declare(strict_types=1);

namespace App\Enums\Updates;

enum UpdateState: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
