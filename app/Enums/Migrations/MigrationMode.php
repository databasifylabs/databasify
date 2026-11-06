<?php

declare(strict_types=1);

namespace App\Enums\Migrations;

enum MigrationMode: string
{
    case Continuous = 'continuous';
    case OneTime = 'one_time';
}
