<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Shared;

use App\DatabaseProviders\Groups\EngineCluster;
use App\Models\DatabaseNode;

final readonly class SentinelCluster extends EngineCluster
{
    public function admit(DatabaseNode $node): void {}

    public function adopt(DatabaseNode $node): void {}

    public function forget(DatabaseNode $primary, DatabaseNode $node): void {}
}
