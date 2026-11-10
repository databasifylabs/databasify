<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\DeployNode;
use App\Contracts\Engines\Flow;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;

final readonly class RedeployNodeFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RollingSteps $rolling,
        private DeployNode $deploy,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        $node = $database->nodes()->find($context->nodeIds[0] ?? null);

        return $node === null ? 'Redeploy node' : "Redeploy {$node->displayName()}";
    }

    public function steps(DatabaseFlow $flow): array
    {
        return [
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [
                Step::prepare("deploy {$node->server->name}", $this->steps->quick(fn () => $this->deploy->handle($node->database, $node))),
                ...$this->rolling->backOnline($flow, $node),
            ]),
            Step::finish('refresh the status', $this->steps->quick(fn () => $this->refresh->handle($flow->database))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }
}
