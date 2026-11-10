<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\LearnEngine;
use App\Contracts\Engines\Flow;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;

final readonly class UpgradeFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RollingSteps $rolling,
        private DeployNode $deploy,
        private LearnEngine $learn,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return "Install {$database->engine()->label} {$database->image_release}";
    }

    public function steps(DatabaseFlow $flow): array
    {
        $database = $flow->database;

        return [
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [
                Step::prepare("deploy {$node->server->name}", $this->steps->quick(fn () => $this->deploy->handle($node->database, $node))),
                ...$this->rolling->backOnline($flow, $node),
            ]),
            Step::finish('read what the engine offers', $this->steps->quick(function () use ($database): void {
                $this->refresh->handle($database);
                $this->learn->handle($database, $database->primaryOrSeed());
            })),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }
}
