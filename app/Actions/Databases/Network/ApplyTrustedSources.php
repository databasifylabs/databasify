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
        $sources = $database->trustedCidrs();
        $nodes = $database->nodesWithServers();
        $nodes->each(
            fn (DatabaseNode $node) => $this->firewall->handle($database, $node, $sources),
        );
        $database->markReady();
    }
}
