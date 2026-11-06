<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mysql;

use App\DatabaseProviders\Concerns\SkipsRestartOnSameConfig;
use App\DatabaseProviders\Groups\EngineNodes;

final readonly class MysqlNodes extends EngineNodes
{
    use SkipsRestartOnSameConfig;
}
