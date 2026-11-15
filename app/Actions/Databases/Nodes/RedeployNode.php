<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;

final readonly class RedeployNode
{
    public function __construct(
        private RollingNodes $rolling,
        private DeployNode $deploy,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database, DatabaseNode $node, string $run): void
    {
        $this->rolling->each($database, function (DatabaseNode $member, bool $expectPrimary) use ($run): void {
            $this->deploy->handle($member->database, $member);
            $this->rolling->backOnline($member, $run, $expectPrimary);
        }, $run, only: $node);

        $this->refresh->handle($database);
        $node->markReady();
    }
}
