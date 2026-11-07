<?php

declare(strict_types=1);

namespace App\Queries\Dashboard;

use App\DTOs\Dashboard\DashboardSummary;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use App\Queries\Servers\ServerSummaryQuery;

final readonly class DashboardSummaryQuery
{
    private function __construct() {}

    public static function current(): self
    {
        return new self;
    }

    public function get(): DashboardSummary
    {
        return new DashboardSummary(
            databases: Database::query()->count(),
            onlineDatabases: Database::query()->ready()->count(),
            servers: ServerSummaryQuery::current()->get(),
            nodes: DatabaseNode::query()->inCurrentProject()->count(),
            readOnlyNodes: DatabaseNode::query()->inCurrentProject()->readOnly()->count(),
            runningLogs: ServerLog::query()->inCurrentProject()->running()->count(),
        );
    }
}
