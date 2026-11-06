<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Postgres;

use App\DatabaseProviders\Groups\EngineCluster;
use App\Models\DatabaseNode;

final readonly class PostgresCluster extends EngineCluster
{
    public function membershipChangesFor(DatabaseNode $joining): bool
    {
        return ! $joining->isReadOnly();
    }
}
