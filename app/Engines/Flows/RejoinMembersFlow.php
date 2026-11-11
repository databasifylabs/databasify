<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\DeployNode;
use App\Contracts\Engines\Flow;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;

final readonly class RejoinMembersFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RollingSteps $rolling,
        private DeployNode $deploy,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return 'Rejoin members';
    }

    public function steps(DatabaseFlow $flow): array
    {
        return [
            ...DatabaseNode::query()->with('server')->findMany($flow->context->nodeIds)->sortBy('position')->values()->toBase()->flatMap(fn (DatabaseNode $node): array => $this->rejoinStepsFor($flow, $node))->all(),
            Step::finish('refresh the status', $this->steps->quick(fn () => $this->refresh->handle($flow->database))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }

    /**
     * @return list<Step>
     */
    private function rejoinStepsFor(DatabaseFlow $flow, DatabaseNode $node): array
    {
        return [
            Step::prepare("start {$node->server->name}", $this->steps->quick(fn () => $this->deploy->handle($node->database, $node))),
            ...$this->rolling->backOnline($flow, $node),
        ];
    }
}
