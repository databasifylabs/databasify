<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Jobs\Servers\CreateServerUserJob;
use App\Models\Server;

final readonly class CreateServerUser
{
    public function handle(Server $server, string $username, ?string $publicKey): void
    {
        $server->ensureReady();
        $server->users()->firstOrCreate(['username' => $username]);

        dispatch(new CreateServerUserJob($server, $username, $publicKey));
    }
}
