<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Engines\EngineClient;
use App\Engines\Requests\ClusterQuorum;
use App\Models\Database;
use App\Models\DatabaseNode;
use Throwable;

final readonly class DetectQuorum
{
    public function __construct(private EngineClient $engine) {}

    public function handle(Database $database): bool
    {
        return $database->reachableMembers()->contains($this->seesMajority(...));
    }

    private function seesMajority(DatabaseNode $node): bool
    {
        try {
            return $this->engine->send($node, new ClusterQuorum)->hasMajority();
        } catch (Throwable) {
            return false;
        }
    }
}
