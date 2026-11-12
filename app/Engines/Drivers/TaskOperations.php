<?php

declare(strict_types=1);

namespace App\Engines\Drivers;

use App\Contracts\Engines\EngineScripts;
use App\Engines\EngineClient;
use App\Engines\Requests\TaskStatus;
use App\Engines\Requests\TaskStop;
use App\Engines\Responses\Operation;
use App\Models\DatabaseNode;

final readonly class TaskOperations
{
    public function __construct(
        private EngineClient $engine,
        private EngineScripts $scripts,
    ) {}

    public function status(DatabaseNode $node, string $key): Operation
    {
        $operation = $this->engine->send($node, new TaskStatus($key));
        $phase = $this->scripts->phaseOf($node, $key);

        if ($phase === null) {
            return $operation;
        }

        return new Operation($operation->key, $operation->kind, $operation->state, $phase, $operation->progress, $operation->error);
    }

    public function cancel(DatabaseNode $node, string $key): Operation
    {
        return $this->engine->send($node, new TaskStop($key));
    }
}
