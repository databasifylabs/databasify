<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Engines\EngineClient;
use App\Engines\Requests\ClusterQuorum;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class DetectQuorum
{
    public function __construct(private EngineClient $engine) {}

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
            return $this->engine->send($node, new ClusterQuorum)->hasMajority();
        } catch (Throwable) {
            return null;
        }
    }
}
