<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Enums\Databases\DatabaseStatus;
use App\Models\Database;

final readonly class RefreshDatabaseStatus
{
    public function __construct(private RefreshNodeStatuses $refreshNodes) {}

    public function handle(Database $database): DatabaseStatus
    {
        return $this->refreshNodes->handle($database, $database->nodesWithServers());
    }
}
