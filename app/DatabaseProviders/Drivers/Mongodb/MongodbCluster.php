<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mongodb;

use App\DatabaseProviders\Groups\EngineCluster;
use App\Models\DatabaseNode;

final readonly class MongodbCluster extends EngineCluster
{
    public function cluster(DatabaseNode $node): void {}

    public function admit(DatabaseNode $node): void {}
}
