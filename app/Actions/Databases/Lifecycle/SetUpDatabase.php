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

    public function handle(Database $database): void
    {
        $database->markCreating();
        $nodes = $database->orderedNodesWithout();

        foreach ($nodes as $node) {
            $this->deploy->handle($database, $node);

            if ($node->isSeed()) {
                $this->learn->handle($database, $node);
            }

            $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node));
            $this->firewall->handle($database, $node, $database->trustedCidrs());
            $node->driver()->nodes()->start();
            $this->tasks->await($node, 'waiting for the node', fn (string $key): Operation => $node->driver()->nodes()->ready($key, expectPrimary: $node->isSeed()));
        }

        $settings = $database->settings;

        foreach ($nodes as $node) {
            try {
                $node->driver()->nodes()->bootstrap(Secrets::of($settings), DefaultAccount::of($settings));
            } catch (EngineUnsupported) {
            }
        }

        $source = $settings->restore;

        if ($source !== null) {
            $seed = $database->seed();
            $storage = Storage::at(StorageProvider::query()->withoutProject()->findOrFail($source->storageProviderId), $source->path);
            $this->tasks->await($seed, 'restoring the archive', fn (string $key): Operation => $seed->driver()->archive()->restore($key, $storage, $source->target));
            $this->restoredAccounts->handle($database);
        }

        if ($nodes->count() > 1) {
            $nodes->each(fn (DatabaseNode $node) => $this->tasks->await($node, 'joining the cluster', fn (string $key): Operation => $node->driver()->cluster()->enable($key)));
        }

        rescue(fn () => $this->logUnits->handle($database, $database->seed()));
        $this->refresh->handle($database);
        $database->markReady();
    }
}
