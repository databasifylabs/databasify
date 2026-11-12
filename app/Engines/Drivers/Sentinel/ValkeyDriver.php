<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Contracts\Engines\EngineDriver;
use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use LogicException;

final readonly class ValkeyDriver implements EngineDriver
{
    public function __construct(private SentinelRequests $requests) {}

    public function answers(EngineRequest $request): bool
    {
        return $this->requests->answers($request);
    }

    public function answer(DatabaseNode $node, EngineRequest $request, ?ServerLog $log): EngineReply
    {
        return $this->requests->answer($node, $request) ?? throw new LogicException("The Valkey driver does not answer {$request->command()}.");
    }
}
