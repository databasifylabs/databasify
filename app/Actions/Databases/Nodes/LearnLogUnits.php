<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Engines\Runtime\Runtime;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class LearnLogUnits
{
    public function __construct(private Runtime $runtime) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $database->update(['log_units' => $this->runtime->readLogs($node, null, 1)->units]);
    }
}
