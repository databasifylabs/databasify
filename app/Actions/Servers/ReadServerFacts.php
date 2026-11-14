<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\DTOs\Servers\ServerFacts;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Exceptions\Ssh\SshAccessDenied;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

final readonly class ReadServerFacts
{
    public const string LOG_NAME = 'Read server facts';

    public function handle(Server $server): ServerFacts
    {
        $before = $server->resources();

        try {
            $facts = ServerFacts::fromJson($server->ssh()->exec('ssh.servers.provision.facts', log: self::LOG_NAME));
        } catch (RemoteScriptFailed|SshConnectionFailed|SshAccessDenied $exception) {
            throw ValidationException::withMessages(['server' => $exception->getMessage()]);
        }

        $server->update($facts->toRow());
        $server->flagDatabasesForRetune($before);

        return $facts;
    }
}
