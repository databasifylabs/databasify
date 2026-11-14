<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Exceptions\Ssh\SshAccessDenied;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

final readonly class CreateServerUser
{
    public function handle(Server $server, string $username, ?string $publicKey): void
    {
        $server->ensureReady();

        try {
            $server->ssh()->exec('ssh.servers.users.create', ['username' => $username, 'publicKey' => $publicKey], "Create user {$username}");
        } catch (RemoteScriptFailed|SshConnectionFailed|SshAccessDenied $exception) {
            throw ValidationException::withMessages(['server' => $exception->getMessage()]);
        }

        $server->users()->firstOrCreate(['username' => $username]);
    }
}
