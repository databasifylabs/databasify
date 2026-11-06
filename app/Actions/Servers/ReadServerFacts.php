<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\DTOs\Servers\ServerFacts;
use App\Models\Server;

final readonly class ReadServerFacts
{
    public const string LOG_NAME = 'Read server facts';

    public function handle(Server $server): ServerFacts
    {
        $facts = ServerFacts::fromJson($server->ssh()->exec('ssh.servers.provision.facts', log: self::LOG_NAME));

        $server->update($facts->toRow());

        return $facts;
    }
}
