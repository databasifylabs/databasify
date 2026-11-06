<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mariadb;

use App\DatabaseProviders\Concerns\SkipsRestartOnSameConfig;
use App\DatabaseProviders\Groups\EngineNodes;
use App\Models\DatabaseNode;

final readonly class MariadbNodes extends EngineNodes
{
    use SkipsRestartOnSameConfig;

    public function wake(DatabaseNode $node): void {}
}
