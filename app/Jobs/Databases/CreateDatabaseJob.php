<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
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
use App\Engines\Runtime\Runtime;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Queues\QueueGroup;
use App\Exceptions\Engines\EngineUnsupported;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\StorageProvider;
use App\Services\Databases\EngineTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(7200)]
#[DeleteWhenMissingModels]
final class CreateDatabaseJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public Database $database) {}

    public function handle(
        DeployNode $deploy,
        LearnEngine $learn,
        BuildNodeConfig $nodeConfig,
        ApplyNodeFirewall $firewall,
        EngineTasks $tasks,
        ReconcileRestoredAccounts $restoredAccounts,
        LearnLogUnits $logUnits,
        RefreshDatabaseStatus $refresh,
    ): void {
        $database = $this->database;
        $database->markCreating();
        $nodes = $this->nodesOf($database);

        foreach ($nodes as $node) {
            $deploy->handle($database, $node);

            if ($node->isSeed()) {
                $learn->handle($database, $node);
            }

            $node->driver()->nodes()->configure($nodeConfig->handle($database, $node));
            $firewall->handle($database, $node, $database->trustedCidrs());
            $node->driver()->nodes()->start();
            $tasks->await($node, 'waiting for the node', fn (string $key): Operation => $node->driver()->nodes()->ready($key, expectPrimary: $node->isSeed()));
        }

        $nodes->each($this->bootstrap(...));
        $this->restoreArchiveOf($database, $tasks, $restoredAccounts);

        if ($nodes->count() > 1) {
            $nodes->each(fn (DatabaseNode $node) => $tasks->await($node, 'joining the cluster', fn (string $key): Operation => $node->driver()->cluster()->enable($key)));
        }

        rescue(fn () => $logUnits->handle($database, $database->seed()));
        $refresh->handle($database);
    }

    protected function endFailed(Database $database): void
    {
        $runtime = resolve(Runtime::class);
        $database->ignoringStop(fn () => $this->nodesOf($database)->each(fn (DatabaseNode $node) => rescue(fn () => $runtime->remove($node, erase: true))));
        $database->markFailed();
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Pending;
    }

    protected function operationName(): string
    {
        return "Create {$this->database->engine()->label} database";
    }

    private function bootstrap(DatabaseNode $node): void
    {
        $settings = $node->database->settings;

        try {
            $node->driver()->nodes()->bootstrap(Secrets::of($settings), DefaultAccount::of($settings));
        } catch (EngineUnsupported) {
        }
    }

    private function restoreArchiveOf(Database $database, EngineTasks $tasks, ReconcileRestoredAccounts $restoredAccounts): void
    {
        $source = $database->settings->restore;

        if ($source === null) {
            return;
        }

        $seed = $database->seed();
        $storage = Storage::at(StorageProvider::query()->withoutProject()->findOrFail($source->storageProviderId), $source->path);
        $tasks->await($seed, 'restoring the archive', fn (string $key): Operation => $seed->driver()->archive()->restore($key, $storage, $source->target));
        $restoredAccounts->handle($database);
    }

    private function nodesOf(Database $database): DatabaseNodeCollection
    {
        return $database->nodes()->with('server', 'database')->orderBy('position')->get();
    }
}
