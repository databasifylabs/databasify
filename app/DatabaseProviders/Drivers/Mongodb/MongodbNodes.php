<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mongodb;

use App\DatabaseProviders\Concerns\SkipsRestartOnSameConfig;
use App\DatabaseProviders\Groups\EngineNodes;

final readonly class MongodbNodes extends EngineNodes
{
    use SkipsRestartOnSameConfig;
}
