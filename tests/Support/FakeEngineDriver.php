<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Engines\EngineDriver;
use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Engines\Requests\UserCreate;
use App\Engines\Responses\UserCreate as UserCreateReply;
use App\Models\DatabaseNode;
use App\Models\ServerLog;

final class FakeEngineDriver implements EngineDriver
{
    /** @var list<string> */
    public static array $answered = [];

    public function answers(EngineRequest $request): bool
    {
        return $request instanceof UserCreate;
    }

    public function answer(DatabaseNode $node, EngineRequest $request, ?ServerLog $log): EngineReply
    {
        self::$answered[] = $request->command();

        return new UserCreateReply(true);
    }
}
