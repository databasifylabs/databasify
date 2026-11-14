<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Engines\Data\Operation;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Closure;

final readonly class FlowSteps
{
    public function __construct(private BuildNodeConfig $nodeConfig) {}

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

    public function configure(Database $database, DatabaseNode $node): Step
    {
        return Step::prepare("configure {$node->server->name}", $this->quick(
            fn () => $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node)),
        ));
    }

    /**
     * @param  Closure(string): Operation  $start
     * @return Closure(DatabaseFlow): StartedOperation
     */
    public function long(DatabaseNode $node, Closure $start, string $log): Closure
    {
        return function (DatabaseFlow $flow) use ($node, $start, $log): StartedOperation {
            $serverLog = $node->openLog("{$node->server->name}: {$log}");
            $operation = new StartedOperation($node->id, $flow->newOperationKey(), $serverLog->id);
            $flow->waitFor($operation);
            $start($operation->key);

            return $operation;
        };
    }
}
