<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\DTOs\Metrics\NodeMetrics;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;
use Illuminate\Support\Collection;
use Throwable;

final readonly class ReadDatabaseMetrics
{
    public function __construct(private NodeRunner $nodeRunner) {}

    /**
     * @return Collection<int, NodeMetrics>
     */
    public function handle(Database $database): Collection
    {
        return $this->nodeRunner->map(
            $database->nodesWithServers(),
            fn (DatabaseNode $node): NodeMetrics => $this->metricsOf($database, $node),
            NodeMetrics::class,
        );
    }

    private function metricsOf(Database $database, DatabaseNode $node): NodeMetrics
    {
        try {
            return $database->provider()->nodes()->metrics($node);
        } catch (Throwable) {
            return NodeMetrics::unreachable($node);
        }
    }
}
