<?php

declare(strict_types=1);

namespace App\Enums\Migrations;

enum MigrationPhase: string
{
    case Working = 'working';
    case CaughtUp = 'caught_up';
    case Stalled = 'stalled';
    case Broken = 'broken';
}
