<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum StepKind: string
{
    case Prepare = 'prepare';
    case CatchUp = 'catch_up';
    case Finish = 'finish';
}
