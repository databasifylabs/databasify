<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySql;

use App\Contracts\Engines\EngineDriver;
use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Engines\Drivers\MySqlFamily\FamilyRequests;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use LogicException;

final readonly class MySqlDriver implements EngineDriver
{
    public function __construct(private FamilyRequests $requests) {}

    public function answers(EngineRequest $request): bool
    {
        return $this->requests->answers($request);
    }

    public function answer(DatabaseNode $node, EngineRequest $request, ?ServerLog $log): EngineReply
    {
        return $this->requests->answer($node, $request) ?? throw new LogicException("The MySQL driver does not answer {$request->command()}.");
    }
}
