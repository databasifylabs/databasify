<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Postgres;

use App\DatabaseProviders\Groups\EngineAccounts;

final readonly class PostgresAccounts extends EngineAccounts
{
    public function reservedUsers(): array
    {
        return ['postgres', 'replicator', 'pgbouncer', 'patroni', 'admin'];
    }

    public function reservedDatabases(): array
    {
        return ['postgres', 'template0', 'template1'];
    }
}
