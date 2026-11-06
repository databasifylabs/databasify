<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\DTOs\Servers\ServerSummary;

final readonly class DashboardSummary
{
    public function __construct(
        public int $databases,
        public int $onlineDatabases,
        public ServerSummary $servers,
        public int $nodes,
        public int $readOnlyNodes,
        public int $runningLogs,
    ) {}

    /**
     * @return array{databases: array{total: int, online: int}, servers: array{total: int, in_use: int, available: int}, nodes: array{total: int, read_only: int}, running_logs: int}
     */
    public function toArray(): array
    {
        return [
            'databases' => ['total' => $this->databases, 'online' => $this->onlineDatabases],
            'servers' => $this->servers->toArray(),
            'nodes' => ['total' => $this->nodes, 'read_only' => $this->readOnlyNodes],
            'running_logs' => $this->runningLogs,
        ];
    }
}
