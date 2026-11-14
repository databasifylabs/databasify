<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class ApplyTrustedSources
{
    public function __construct(
        private ApplyNodeFirewall $firewall,
    ) {}

    public function handle(Database $database): void
    {
        $database->nodesWithServers()->members()->each(
            fn (DatabaseNode $node) => $this->firewall->handle($database, $node, $node->trustedCidrs()),
        );
        $database->markReady();
    }
}
