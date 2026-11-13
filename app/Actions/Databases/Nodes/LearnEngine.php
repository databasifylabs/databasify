<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class LearnEngine
{
    public function handle(Database $database, DatabaseNode $node): void
    {
        $database->update(['config_fields' => $node->driver()->config()->fields()]);
    }
}
