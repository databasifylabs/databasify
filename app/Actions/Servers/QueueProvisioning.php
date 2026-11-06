<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

final readonly class QueueProvisioning
{
    public function handle(Server $server, bool $disablePasswordLogin = false): void
    {
        if ($server->isBeingSetUp()) {
            throw ValidationException::withMessages(['server' => "{$server->name} is already being set up."]);
        }

        $server->markPending();
        dispatch(new ProvisionServerJob($server, $disablePasswordLogin));
    }
}
