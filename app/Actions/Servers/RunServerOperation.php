<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\Servers\ServerOperation;
use App\Models\Server;

final readonly class RunServerOperation
{
    public function handle(Server $server, ServerOperation $operation): void
    {
        $server->ensureDatabasesSpareIt();
        $server->ensureReady();
        dispatch($operation->jobFor($server));
    }
}
