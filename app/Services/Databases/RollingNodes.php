<?php

declare(strict_types=1);

namespace App\Services\Databases;

use App\Engines\Data\Operation;
use App\Enums\Databases\NodeRole;
use App\Models\Database;
use App\Models\DatabaseNode;
use Closure;

final readonly class RollingNodes
{
    public function __construct(private EngineTasks $tasks) {}

    /**
     * @param  Closure(DatabaseNode, bool): mixed  $work
     */
    public function each(Database $database, Closure $work, string $run, bool $failover = true, ?DatabaseNode $only = null, bool $resumable = true): void
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
            $expectPrimary = $isPrimary && $standby === null;

            if ($resumable && $this->tasks->started($node, $run, 'ready')) {
                $this->backOnline($node, $run, $expectPrimary);

                continue;
            }

            if ($isPrimary && $standby !== null && $node->driver()->nodes()->status()->role === NodeRole::Primary) {
                $node->driver()->cluster()->failover($standby->privateIp());
            }

            $work($node, $expectPrimary);
        }
    }

    public function begun(Database $database, string $run): bool
    {
        return $database->nodesWithServers()->contains(fn (DatabaseNode $node): bool => $this->tasks->started($node, $run, 'ready'));
    }

    public function restart(Database $database, string $run): void
    {
        $this->each($database, function (DatabaseNode $node, bool $expectPrimary) use ($run): void {
            $node->driver()->nodes()->restart();
            $this->backOnline($node, $run, $expectPrimary);
        }, $run);
    }

    public function backOnline(DatabaseNode $node, string $run, bool $expectPrimary): void
    {
        $this->tasks->await($node, $run, 'ready', 'waiting for the node', fn (string $key): Operation => $node->driver()->nodes()->ready($key, $expectPrimary));

        if (! $node->database->isClustered()) {
            return;
        }

        $this->tasks->await($node, $run, 'rejoin', 'rejoining the cluster', fn (string $key): Operation => $node->driver()->cluster()->rejoin($key, $node->isReadOnly()));
    }
}
