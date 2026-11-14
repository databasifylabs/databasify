<?php

declare(strict_types=1);

namespace App\Services\Databases;

use App\Engines\Data\Operation;
use App\Models\Database;
use App\Models\DatabaseNode;
use Closure;

final readonly class RollingNodes
{
    public function __construct(private EngineTasks $tasks) {}

    /**
     * @param  Closure(DatabaseNode, bool): mixed  $work
     */
    public function each(Database $database, Closure $work, bool $failover = true, ?DatabaseNode $only = null): void
    {
        $nodes = $database->nodesWithServers();
        $primary = $database->primaryOrSeed();
        $standbys = $nodes->standbysBesides($primary);
        $standby = $failover ? $standbys->first() : null;
        $order = [...$nodes->readOnly()->all(), ...$standbys->all(), $primary];

        foreach ($order as $node) {
            if ($only !== null && ! $node->is($only)) {
                continue;
            }

            $isPrimary = $node->is($primary);

            if ($isPrimary && $standby !== null) {
                $node->driver()->cluster()->failover($standby->privateIp());
            }

            $work($node, $isPrimary && $standby === null);
        }
    }

    public function restart(Database $database): void
    {
        $this->each($database, function (DatabaseNode $node, bool $expectPrimary): void {
            $node->driver()->nodes()->restart();
            $this->backOnline($node, $expectPrimary);
        });
    }

    public function backOnline(DatabaseNode $node, bool $expectPrimary): void
    {
        $this->tasks->await($node, 'waiting for the node', fn (string $key): Operation => $node->driver()->nodes()->ready($key, $expectPrimary));

        if (! $node->database->isClustered()) {
            return;
        }

        $this->tasks->await($node, 'rejoining the cluster', fn (string $key): Operation => $node->driver()->cluster()->rejoin($key, $node->isReadOnly()));
    }
}
