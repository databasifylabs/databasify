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

    public function handle(Database $database, DatabaseNode $node, string $run): void
    {
        $joiner = $node->load('server');
        $existing = $database->nodesOtherThan($joiner)->load('server')->sortBy('position')->values();

        $readied = $this->tasks->started($joiner, $run, 'ready');

        if (! $readied) {
            $this->deploy->handle($database, $joiner);
        }

        $database->nodes()->with('server')->get()->each(fn (DatabaseNode $node) => $this->firewall->handle($database, $node, $database->trustedCidrs()));

        if (! $readied) {
            $joiner->driver()->nodes()->configure($this->nodeConfig->handle($database, $joiner));
            $joiner->driver()->nodes()->start();
        }

        $this->tasks->await($joiner, $run, 'ready', 'waiting for the node', fn (string $key): Operation => $joiner->driver()->nodes()->ready($key, expectPrimary: false));

        $existing->each(function (DatabaseNode $node) use ($database): void {
            $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node));
            $node->driver()->cluster()->membership($this->nodeConfig->membersOf($database));
        });

        if ($existing->count() === 1) {
            $peer = $existing->sole();
            $this->tasks->await($peer, $run, 'enable', 'enabling the cluster', fn (string $key): Operation => $peer->driver()->cluster()->enable($key));
        }

        $this->tasks->await($joiner, $run, 'copy', 'copying the data', fn (string $key): Operation => $joiner->driver()->cluster()->enable($key));
        $database->matchModeToMembers();
        $this->refresh->handle($database);
        $database->markReady();
    }
}
