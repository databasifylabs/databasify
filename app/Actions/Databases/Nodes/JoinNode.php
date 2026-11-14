<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Engines\Data\Operation;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;

final readonly class JoinNode
{
    public function __construct(
        private DeployNode $deploy,
        private ApplyNodeFirewall $firewall,
        private BuildNodeConfig $nodeConfig,
        private EngineTasks $tasks,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $joiner = $node->load('server');
        $existing = $database->nodesOtherThan($joiner)->load('server')->sortBy('position')->values();

        $this->deploy->handle($database, $joiner);
        $database->nodes()->with('server')->get()->each(fn (DatabaseNode $node) => $this->firewall->handle($database, $node, $database->trustedCidrs()));
        $joiner->driver()->nodes()->configure($this->nodeConfig->handle($database, $joiner));
        $joiner->driver()->nodes()->start();
        $this->tasks->await($joiner, 'waiting for the node', fn (string $key): Operation => $joiner->driver()->nodes()->ready($key, expectPrimary: false));

        $existing->each(function (DatabaseNode $node) use ($database): void {
            $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node));
            $node->driver()->cluster()->membership($this->nodeConfig->membersOf($database));
        });

        if ($existing->count() === 1) {
            $peer = $existing->sole();
            $this->tasks->await($peer, 'enabling the cluster', fn (string $key): Operation => $peer->driver()->cluster()->enable($key));
        }

        $this->tasks->await($joiner, 'copying the data', fn (string $key): Operation => $joiner->driver()->cluster()->enable($key));
        $database->matchModeToMembers();
        $this->refresh->handle($database);
        $database->markReady();
    }
}
