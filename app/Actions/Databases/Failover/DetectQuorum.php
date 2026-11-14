<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class DetectQuorum
{
    public function handle(Database $database): bool
    {
        $readings = $database->reachableMembers()->map($this->majorityOf(...));

        if ($readings->every(fn (?bool $majority): bool => $majority === null)) {
            throw ValidationException::withMessages(['database' => "Databasify could not read the quorum of {$database->name}, try again in a moment."]);
        }

        return $readings->contains(true);
    }

    private function majorityOf(DatabaseNode $node): ?bool
    {
        try {
            return $node->driver()->cluster()->quorum()->hasMajority();
        } catch (Throwable) {
            return null;
        }
    }
}
