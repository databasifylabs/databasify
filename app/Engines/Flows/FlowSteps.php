<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Engines\EngineClient;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Closure;

final readonly class FlowSteps
{
    public function __construct(private EngineClient $engine) {}

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

    /**
     * @param  Closure(string): EngineRequest<EngineReply>  $request
     * @return Closure(DatabaseFlow): StartedOperation
     */
    public function long(DatabaseNode $node, Closure $request): Closure
    {
        return function (DatabaseFlow $flow) use ($node, $request): StartedOperation {
            $key = $flow->newOperationKey();
            $this->engine->send($node, $request($key));

            return new StartedOperation($node->id, $key);
        };
    }
}
