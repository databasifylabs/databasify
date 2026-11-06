<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\DTOs\Databases\EngineImage;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class DeployDatabaseNode
{
    public function __construct(
        private ConfigureDatabaseNode $configure,
        private ApplyNodeFirewall $firewall,
        private StartDatabaseNode $start,
    ) {}

    public function handle(Database $database, DatabaseNode $node, bool $expectPrimary = false, bool $fresh = false, bool $joining = false, ?EngineImage $image = null): void
    {
        $this->configure->handle($database, $node, $fresh, $image);
        $this->firewall->handle($database, $node, $database->trustedCidrs());
        $this->start->handle($database, $node, $expectPrimary, $joining);
    }
}
