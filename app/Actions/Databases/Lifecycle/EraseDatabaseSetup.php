<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Engines\Runtime\Runtime;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class EraseDatabaseSetup
{
    public function __construct(private Runtime $runtime) {}

    public function handle(Database $database): void
    {
        $database->ignoringStop(fn () => $database->orderedNodesWithout()->each(fn (DatabaseNode $node) => rescue(fn () => $this->runtime->remove($node, erase: true))));
    }
}
