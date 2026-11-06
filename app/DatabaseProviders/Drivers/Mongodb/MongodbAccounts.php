<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mongodb;

use App\DatabaseProviders\Groups\EngineAccounts;

final readonly class MongodbAccounts extends EngineAccounts
{
    public function reservedUsers(): array
    {
        return ['root', 'admin', 'pbm', '__system'];
    }

    public function reservedDatabases(): array
    {
        return ['admin', 'local', 'config', MongodbMigration::CHECKPOINT_DATABASE];
    }
}
