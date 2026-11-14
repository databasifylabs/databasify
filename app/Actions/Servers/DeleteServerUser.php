<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Exceptions\Ssh\SshAccessDenied;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\ServerUser;
use Illuminate\Validation\ValidationException;

final readonly class DeleteServerUser
{
    public function handle(ServerUser $user): void
    {
        $user->server->ensureReady();

        try {
            $user->server->ssh()->exec('ssh.servers.users.delete', ['username' => $user->username], "Delete user {$user->username}");
        } catch (RemoteScriptFailed|SshConnectionFailed|SshAccessDenied $exception) {
            throw ValidationException::withMessages(['server' => $exception->getMessage()]);
        }

        $user->delete();
    }
}
