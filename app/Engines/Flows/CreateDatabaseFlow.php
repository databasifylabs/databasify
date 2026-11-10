<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Nodes\BuildNodeRequest;
use App\Actions\Databases\Nodes\DeployNode;
use App\Contracts\Engines\Flow;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Secrets;
use App\Engines\EngineClient;
use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\HostRemove;
use App\Engines\Requests\NodeBootstrap;
use App\Engines\Requests\NodeReady;
use App\Engines\Requests\NodeStart;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Closure;
use Throwable;

final readonly class CreateDatabaseFlow implements Flow
{
    public function __construct(
        private EngineClient $engine,
        private DeployNode $deploy,
        private BuildNodeRequest $nodeRequest,
        private ApplyNodeFirewall $firewall,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database): string
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
            ...$joinSteps,
            Step::finish('refresh the status', $this->quick(fn (DatabaseFlow $flow) => $this->refresh->handle($flow->database))),
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
            $this->engine->send($node, new HostRemove($node->database_id, erase: true), "{$node->server->name}: removing the failed setup", EngineClient::REMOVE_TIMEOUT);
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
            Step::prepare("deploy {$server}", $this->quick(fn () => $this->deploy->handle($database, $node))),
            Step::prepare("configure {$server}", $this->quick(fn () => $this->engine->send($node, $this->nodeRequest->handle($database, $node), "{$server}: configuring the node"))),
            Step::prepare("open the firewall on {$server}", $this->quick(fn () => $this->firewall->handle($database, $node, $database->trustedCidrs()))),
            Step::prepare("start {$server}", $this->quick(fn () => $this->engine->send($node, new NodeStart, "{$server}: starting the engine"))),
            Step::prepare("wait for {$server}", fn (DatabaseFlow $flow): StartedOperation => $this->startOn($node, $flow, fn (string $key): NodeReady => new NodeReady($key, expectPrimary: $node->isSeed()))),
        ];
    }

    private function bootstrapStepFor(DatabaseNode $node): Step
    {
        return Step::finish("bootstrap {$node->server->name}", $this->quick(function () use ($node): void {
            $settings = $node->database->settings;

            try {
                $this->engine->send($node, new NodeBootstrap(Secrets::of($settings), DefaultAccount::of($settings)), "{$node->server->name}: creating the users");
            } catch (EngineUnsupported) {
            }
        }));
    }

    private function joinStepFor(DatabaseNode $node): Step
    {
        return Step::catchUp("join {$node->server->name} to the cluster", fn (DatabaseFlow $flow): StartedOperation => $this->startOn($node, $flow, fn (string $key): ClusterEnable => new ClusterEnable($key)));
    }

    /**
     * @param  Closure(DatabaseFlow): mixed  $work
     * @return Closure(DatabaseFlow): null
     */
    private function quick(Closure $work): Closure
    {
        return function (DatabaseFlow $flow) use ($work): null {
            $work($flow);

            return null;
        };
    }

    /**
     * @param  Closure(string): (NodeReady|ClusterEnable)  $request
     */
    private function startOn(DatabaseNode $node, DatabaseFlow $flow, Closure $request): StartedOperation
    {
        $key = $flow->newOperationKey();
        $this->engine->send($node, $request($key));

        return new StartedOperation($node->id, $key);
    }

    private function nodesOf(DatabaseFlow $flow): DatabaseNodeCollection
    {
        return $flow->database->nodes()->with('server', 'database')->orderBy('position')->get();
    }
}
