<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\DTOs\Metrics\NodeMetrics;
use App\Engines\EngineClient;
use App\Engines\Requests\NodeMetrics as EngineNodeMetrics;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;
use App\Support\Ssh\KeyValueOutput;
use Illuminate\Support\Collection;
use Throwable;

final readonly class ReadDatabaseMetrics
{
    public function __construct(
        private NodeRunner $nodeRunner,
        private EngineClient $engine,
    ) {}

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
            $values = [...$this->hostMetricsOf($database, $node), ...$this->engine->send($node, new EngineNodeMetrics)->toValues()];

            return new NodeMetrics($node->id, $node->server->name, true, $values);
        } catch (Throwable) {
            return NodeMetrics::unreachable($node);
        }
    }

    /**
     * @return array<string, string>
     */
    private function hostMetricsOf(Database $database, DatabaseNode $node): array
    {
        return KeyValueOutput::parse($node->ssh()->exec('ssh.databases.monitoring.metrics', ['directory' => "/srv/{$database->engine}"]));
    }
}
