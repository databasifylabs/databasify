<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Contracts\Engines\Flow;
use App\Engines\Requests\ClusterRejoin;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;

final readonly class RejoinMembersFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return 'Rejoin members';
    }

    public function steps(DatabaseFlow $flow): array
    {
        return [
            ...DatabaseNode::query()->with('server')->findMany($flow->context->nodeIds)->sortBy('position')->values()->toBase()->map($this->rejoinStepFor(...))->all(),
            Step::finish('refresh the status', $this->steps->quick(fn () => $this->refresh->handle($flow->database))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }

    private function rejoinStepFor(DatabaseNode $node): Step
    {
        return Step::prepare("rejoin {$node->server->name}", $this->steps->long($node, fn (string $key): ClusterRejoin => new ClusterRejoin($key, readOnly: false)));
    }
}
