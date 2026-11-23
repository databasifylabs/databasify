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
        $existing = $database->nodesOtherThan($joiner);
        $configure = fn (DatabaseNode $member) => $member->driver()->nodes()->configure($this->nodeConfig->handle($database, $member));
        $enable = fn (DatabaseNode $seed) => $this->tasks->await($seed, $run, 'enable', 'enabling the cluster', fn (string $key): Operation => $seed->driver()->cluster()->enable($key));
        $seedFirst = $existing->hasSole() && $database->facts()->seedClustersFirst;
        $readied = $this->tasks->started($joiner, $run, 'ready');

        if (! $readied && $seedFirst) {
            $configure($existing->sole());
            $enable($existing->sole());
        }

        if (! $readied) {
            $this->deploy->handle($database, $joiner);
        }

        $database->nodes()->get()->each(fn (DatabaseNode $member) => $this->firewall->handle($database, $member, $member->trustedCidrs()));

        if (! $readied) {
            $configure($joiner);
            $joiner->driver()->nodes()->start();
        }

        $this->tasks->await($joiner, $run, 'ready', 'waiting for the node', fn (string $key): Operation => $joiner->driver()->nodes()->ready($key, expectPrimary: false));

        $existing->each($configure);

        if ($existing->hasMany()) {
            $members = $database->orderedNodesWithout()->toMembers();
            $existing->each(fn (DatabaseNode $member) => $member->driver()->cluster()->membership($members));
        }

        if ($existing->hasSole() && ! $seedFirst) {
            $enable($existing->sole());
        }

        $this->tasks->await($joiner, $run, 'copy', 'copying the data', fn (string $key): Operation => $joiner->driver()->cluster()->enable($key));
        $database->matchModeToMembers();
        $this->refresh->handle($database);
        $database->markReady();
    }
}
