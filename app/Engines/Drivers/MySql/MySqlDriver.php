<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySql;

use App\Contracts\Engines\EngineDriver;
use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Engines\Drivers\MySqlFamily\Readings;
use App\Engines\Requests\NodeMetrics;
use App\Engines\Requests\NodeStatus;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use LogicException;

final readonly class MySqlDriver implements EngineDriver
{
    public function __construct(
        private Readings $readings,
    ) {}

    public function answers(EngineRequest $request): bool
    {
        return $request instanceof NodeStatus
            || $request instanceof NodeMetrics;
    }

    public function answer(DatabaseNode $node, EngineRequest $request, ?ServerLog $log): EngineReply
    {
        return match (true) {
            $request instanceof NodeStatus => $this->readings->status($node),
            $request instanceof NodeMetrics => $this->readings->metrics($node),
            default => throw new LogicException("The MySQL driver does not answer {$request->command()}."),
        };
    }
}
