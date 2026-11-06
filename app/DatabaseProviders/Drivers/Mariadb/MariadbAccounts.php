<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mariadb;

use App\DatabaseProviders\Groups\EngineAccounts;

final readonly class MariadbAccounts extends EngineAccounts
{
    public function reservedUsers(): array
    {
        return ['root', 'mysql', 'mariadb.sys', 'sst', 'replicator', 'admin'];
    }

    public function reservedDatabases(): array
    {
        return ['mysql', 'sys', 'information_schema', 'performance_schema', 'databasify'];
    }
}
