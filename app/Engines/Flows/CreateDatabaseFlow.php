<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\LearnEngine;
use App\Actions\Databases\Nodes\LearnLogUnits;
use App\Actions\Databases\Recovery\ReconcileRestoredAccounts;
use App\Contracts\Engines\Flow;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;
use App\Engines\Data\Storage;
use App\Engines\Runtime\Runtime;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use App\Models\StorageProvider;
use Throwable;

final readonly class CreateDatabaseFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private Runtime $runtime,
        private DeployNode $deploy,
        private ApplyNodeFirewall $firewall,
        private RefreshDatabaseStatus $refresh,
        private LearnEngine $learn,
        private LearnLogUnits $logUnits,
        private ReconcileRestoredAccounts $restoredAccounts,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return "Create {$database->engine()->label} database";
    }

    public function steps(DatabaseFlow $flow): array
    {
        $nodes = $this->nodesOf($flow)->toBase();
        $joinSteps = $nodes->count() > 1 ? $nodes->map($this->joinStepFor(...))->all() : [];

        return [
            ...$nodes->flatMap($this->prepareStepsFor(...))->all(),
            ...$nodes->map($this->bootstrapStepFor(...))->all(),
            ...$this->restoreStepsOf($flow),
            ...$joinSteps,
            Step::finish('refresh the status', $this->steps->quick(function (DatabaseFlow $flow): void {
                rescue(fn () => $this->logUnits->handle($flow->database, $flow->database->seed()));
                $this->refresh->handle($flow->database);
            })),
        ];
    }

    /**
     * @return list<Step>
     */
    private function restoreStepsOf(DatabaseFlow $flow): array
    {
        $database = $flow->database;
        $source = $database->settings->restore;

        if ($source === null) {
            return [];
        }

        $storage = Storage::at(StorageProvider::query()->withoutProject()->findOrFail($source->storageProviderId), $source->path);

        return [
            Step::catchUp('restore the archive', $this->steps->long($database->seed(), fn (string $key): Operation => $database->seed()->driver()->archive()->restore($key, $storage, $source->target), 'restoring the archive')),
            Step::finish('check the restored accounts', $this->steps->quick(fn () => $this->restoredAccounts->handle($database))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        $this->nodesOf($flow)->each($this->eraseSetupOn(...));

        $flow->database->markFailed();
    }

    private function eraseSetupOn(DatabaseNode $node): void
    {
        try {
            $this->runtime->remove($node, erase: true);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return list<Step>
     */
    private function prepareStepsFor(DatabaseNode $node): array
    {
        $server = $node->server->name;
        $database = $node->database;

        return [
            Step::prepare("deploy {$server}", $this->steps->quick(fn () => $this->deploy->handle($database, $node))),
            ...($node->isSeed() ? [Step::prepare('read what the engine offers', $this->steps->quick(fn () => $this->learn->handle($database, $node)))] : []),
            $this->steps->configure($database, $node),
            Step::prepare("open the firewall on {$server}", $this->steps->quick(fn () => $this->firewall->handle($database, $node, $database->trustedCidrs()))),
            Step::prepare("start {$server}", $this->steps->quick(fn () => $node->driver()->nodes()->start())),
            Step::prepare("wait for {$server}", $this->steps->long($node, fn (string $key): Operation => $node->driver()->nodes()->ready($key, expectPrimary: $node->isSeed()), 'waiting for the node')),
        ];
    }

    private function bootstrapStepFor(DatabaseNode $node): Step
    {
        return Step::finish("bootstrap {$node->server->name}", $this->steps->quick(function () use ($node): void {
            $settings = $node->database->settings;

            try {
                $node->driver()->nodes()->bootstrap(Secrets::of($settings), DefaultAccount::of($settings));
            } catch (EngineUnsupported) {
            }
        }));
    }

    private function joinStepFor(DatabaseNode $node): Step
    {
        return Step::catchUp("join {$node->server->name} to the cluster", $this->steps->long($node, fn (string $key): Operation => $node->driver()->cluster()->enable($key), 'joining the cluster'));
    }

    private function nodesOf(DatabaseFlow $flow): DatabaseNodeCollection
    {
        return $flow->database->nodes()->with('server', 'database')->orderBy('position')->get();
    }
}
