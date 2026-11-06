<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mariadb;

use App\DatabaseProviders\Groups\EngineCluster;
use App\Models\DatabaseNode;

final readonly class MariadbCluster extends EngineCluster
{
    public function adopt(DatabaseNode $node): void {}

    public function cloneDonorFor(DatabaseNode $joining): ?DatabaseNode
    {
        return $this->healthyStandbyOtherThan($joining);
    }

    public function membershipChangesFor(DatabaseNode $joining): bool
    {
        return ! $joining->isReadOnly();
    }

    public function forget(DatabaseNode $primary, DatabaseNode $node): void {}
}
