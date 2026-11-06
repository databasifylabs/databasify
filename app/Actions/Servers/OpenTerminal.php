<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Jobs\Servers\RunTerminalSessionJob;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\TerminalSession;
use Illuminate\Validation\ValidationException;

final readonly class OpenTerminal
{
    public function handle(Server $server, User $user, int $columns, int $rows): TerminalSession
    {
        if (! $server->isReady()) {
            throw ValidationException::withMessages(['server' => "{$server->name} is not ready for a terminal yet."]);
        }

        $session = TerminalSession::open($server, $user);
        dispatch(new RunTerminalSessionJob($server, $session, $columns, $rows));

        return $session;
    }
}
