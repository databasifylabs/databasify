<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Nodes\BuildNodeRequest;
use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Engines\EngineClient;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Closure;

final readonly class FlowSteps
{
    public function __construct(
        private EngineClient $engine,
        private BuildNodeRequest $nodeRequest,
    ) {}

    /**
     * @param  Closure(DatabaseFlow): mixed  $work
     * @return Closure(DatabaseFlow): null
     */
    public function quick(Closure $work): Closure
    {
        return function (DatabaseFlow $flow) use ($work): null {
            $work($flow);

            return null;
        };
    }

    /**
     * @param  EngineRequest<EngineReply>  $request
     * @return Closure(DatabaseFlow): null
     */
    public function send(DatabaseNode $node, EngineRequest $request, string $log): Closure
    {
        return $this->quick(fn () => $this->engine->send($node, $request, "{$node->server->name}: {$log}"));
    }

    public function configure(Database $database, DatabaseNode $node): Step
    {
        return Step::prepare("configure {$node->server->name}", $this->quick(
            fn () => $this->engine->send($node, $this->nodeRequest->handle($database, $node), "{$node->server->name}: configuring the node"),
        ));
    }

    /**
     * @param  Closure(string): EngineRequest<EngineReply>  $request
     * @return Closure(DatabaseFlow): StartedOperation
     */
    public function long(DatabaseNode $node, Closure $request): Closure
    {
        return function (DatabaseFlow $flow) use ($node, $request): StartedOperation {
            $operation = new StartedOperation($node->id, $flow->newOperationKey());
            $flow->waitFor($operation);
            $this->engine->send($node, $request($operation->key));

            return $operation;
        };
    }
}
