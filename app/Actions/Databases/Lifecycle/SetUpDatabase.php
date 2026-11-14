<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\LearnEngine;
use App\Actions\Databases\Nodes\LearnLogUnits;
use App\Actions\Databases\Recovery\ReconcileRestoredAccounts;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;
use App\Engines\Data\Storage;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\StorageProvider;
use App\Services\Databases\EngineTasks;

final readonly class SetUpDatabase
{
    public function __construct(
        private DeployNode $deploy,
        private LearnEngine $learn,
        private BuildNodeConfig $nodeConfig,
        private ApplyNodeFirewall $firewall,
        private EngineTasks $tasks,
        private ReconcileRestoredAccounts $restoredAccounts,
        private LearnLogUnits $logUnits,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database, string $run): void
    {
        $database->markCreating();
        $nodes = $database->orderedNodesWithout();

        foreach ($nodes as $node) {
            if (! $this->tasks->started($node, $run, 'ready')) {
                $this->deploy->handle($database, $node);

                if ($node->isSeed()) {
                    $this->learn->handle($database, $node);
                }

                $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node));
                $this->firewall->handle($database, $node, $node->trustedCidrs());
                $node->driver()->nodes()->start();
            }

            $this->tasks->await($node, $run, 'ready', 'waiting for the node', fn (string $key): Operation => $node->driver()->nodes()->ready($key, expectPrimary: $node->isSeed()));
        }

        $settings = $database->settings;
        $source = $settings->restore;
        $seed = $database->seed();
        $clustered = $nodes->count() > 1;
        $joining = $clustered && $this->tasks->started($nodes->firstOrFail(), $run, 'join');

        if (! $joining && ($source === null || ! $this->tasks->started($seed, $run, 'restore'))) {
            foreach ($nodes as $node) {
                try {
                    $node->driver()->nodes()->bootstrap(Secrets::of($settings), DefaultAccount::of($settings));
                } catch (EngineUnsupported) {
                }
            }
        }

        if ($source !== null) {
            $storage = Storage::at(StorageProvider::query()->withoutProject()->findOrFail($source->storageProviderId), $source->path);
            $this->tasks->await($seed, $run, 'restore', 'restoring the archive', fn (string $key): Operation => $seed->driver()->archive()->restore($key, $storage, $source->target));

            if (! $joining) {
                $this->restoredAccounts->handle($database);
            }
        }

        if ($clustered) {
            $nodes->each(fn (DatabaseNode $node) => $this->tasks->await($node, $run, 'join', 'joining the cluster', fn (string $key): Operation => $node->driver()->cluster()->enable($key)));
        }

        rescue(fn () => $this->logUnits->handle($database, $database->seed()));
        $this->refresh->handle($database);
        $database->markReady();
    }
}
