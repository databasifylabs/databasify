<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Jobs\Servers\DeleteServerUserJob;
use App\Models\ServerUser;

final readonly class DeleteServerUser
{
    public function handle(ServerUser $user): void
    {
        $user->server->ensureReady();

        dispatch(new DeleteServerUserJob($user->server, $user->username));
        $user->delete();
    }
}
