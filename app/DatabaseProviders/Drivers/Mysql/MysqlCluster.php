<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mysql;

use App\DatabaseProviders\Groups\EngineCluster;
use App\Models\DatabaseNode;

final readonly class MysqlCluster extends EngineCluster
{
    public function admit(DatabaseNode $node): void {}

    public function cloneDonorFor(DatabaseNode $joining): ?DatabaseNode
    {
        return $this->healthyStandbyOtherThan($joining);
    }

    public function updateMembership(DatabaseNode $node): void {}

    public function rejoin(DatabaseNode $node): void {}

    public function recoveryPosition(DatabaseNode $node): ?int
    {
        return null;
    }
}
