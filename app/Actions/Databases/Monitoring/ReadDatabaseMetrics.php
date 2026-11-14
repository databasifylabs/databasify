<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\DTOs\Metrics\NodeMetrics;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Ssh\KeyValueOutput;
use Illuminate\Support\Collection;
use Throwable;

final readonly class ReadDatabaseMetrics
{
    /**
     * @return Collection<int, NodeMetrics>
     */
    public function handle(Database $database): Collection
    {
        return $database->nodesWithServers()->map(
            function (DatabaseNode $node) use ($database): NodeMetrics {
                try {
                    $engine = $database->runsSupportedSdk() ? $node->driver()->nodes()->metrics()->toValues() : [];
                    $host = KeyValueOutput::parse($node->ssh()->exec('ssh.databases.monitoring.metrics', ['directory' => "/srv/{$database->engine}"]));

                    return new NodeMetrics($node->id, $node->server->name, true, [...$host, ...$engine]);
                } catch (Throwable) {
                    return NodeMetrics::unreachable($node);
                }
            },
        );
    }
}
