<?php

declare(strict_types=1);

namespace App\Contracts\Engines;

use App\Models\DatabaseNode;
use App\Models\ServerLog;

interface EngineDriver
{
    /**
     * @param  EngineRequest<EngineReply>  $request
     */
    public function answers(EngineRequest $request): bool;

    /**
     * @param  EngineRequest<EngineReply>  $request
     */
    public function answer(DatabaseNode $node, EngineRequest $request, ?ServerLog $log): EngineReply;
}
