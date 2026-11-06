<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum ProcessRestart: string
{
    case Always = 'always';
    case OnFailure = 'on-failure';
}
