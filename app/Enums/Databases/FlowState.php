<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum FlowState: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
