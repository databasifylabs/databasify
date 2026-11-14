<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySql;

use App\Engines\Drivers\MySqlFamily\MySqlFamilyDriver;
use App\Engines\Drivers\MySqlFamily\MySqlFamilyEngine;

final readonly class MySqlDriver extends MySqlFamilyDriver
{
    public static function engine(): MySqlFamilyEngine
    {
        return MySqlFamilyEngine::of('mysql');
    }
}
