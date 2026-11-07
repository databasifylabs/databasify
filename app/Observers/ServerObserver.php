<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Models\DeleteChildren;
use App\Models\Server;

final readonly class ServerObserver
{
    public function __construct(private DeleteChildren $children) {}

    public function deleted(Server $server): void
    {
        $this->children->handle($server->users(), $server->serverLogs());
    }
}
