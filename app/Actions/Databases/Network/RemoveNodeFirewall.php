<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class RemoveNodeFirewall
{
    public function handle(Database $database, DatabaseNode $node): void
    {
        $node->ssh()->exec(
            'ssh.databases.network.trusted-sources',
            ['table' => $database->firewallTable(), 'remove' => true],
            "{$node->server->name}: removing firewall rules",
        );
    }
}
