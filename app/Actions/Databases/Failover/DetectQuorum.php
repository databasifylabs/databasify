<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class DetectQuorum
{
    public function handle(Database $database): bool
    {
        $readings = $database->reachableMembers()->map(fn (DatabaseNode $node): ?bool => rescue(fn (): bool => $node->driver()->cluster()->quorum()->hasMajority(), null, report: false));

        if ($readings->every(fn (?bool $majority): bool => $majority === null)) {
            throw ValidationException::withMessages(['database' => "Databasify could not read the quorum of {$database->name}, try again in a moment."]);
        }

        return $readings->contains(true);
    }
}
