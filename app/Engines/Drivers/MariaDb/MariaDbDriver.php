<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MariaDb;

use App\Engines\Drivers\MySqlFamily\MySqlFamilyDriver;
use App\Engines\Drivers\MySqlFamily\MySqlFamilyEngine;

final readonly class MariaDbDriver extends MySqlFamilyDriver
{
    public static function engine(): MySqlFamilyEngine
    {
        return MySqlFamilyEngine::of('mariadb');
    }
}
