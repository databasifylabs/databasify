<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\Database;

final readonly class UnclusterDatabase
{
    public function __construct(private ConfigureDatabaseNode $configure) {}

    public function handle(Database $database): void
    {
        if ($database->isClustered()) {
            return;
        }

        $node = $database->nodes()->sole();
        $this->configure->handle($database, $node);
        $database->provider()->cluster()->uncluster($node);
    }
}
