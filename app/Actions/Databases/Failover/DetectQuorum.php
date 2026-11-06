<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Models\Database;
use App\Models\DatabaseNode;
use Throwable;

final readonly class DetectQuorum
{
    public function handle(Database $database): bool
    {
        return $database->reachableMembers()->contains(fn (DatabaseNode $node): bool => $this->seesMajority($database, $node));
    }

    private function seesMajority(Database $database, DatabaseNode $node): bool
    {
        try {
            return $database->provider()->cluster()->quorum($node)->hasMajority();
        } catch (Throwable) {
            return false;
        }
    }
}
