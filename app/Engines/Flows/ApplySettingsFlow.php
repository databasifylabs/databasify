<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Contracts\Engines\Flow;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;

final readonly class ApplySettingsFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RollingSteps $rolling,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return 'Apply settings';
    }

    public function steps(DatabaseFlow $flow): array
    {
        $database = $flow->database;
        $primary = $database->nodes()->with('server')->findOrFail($flow->context->targetNodeId);

        return [
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [$this->steps->configure($database, $node)], failover: false),
            Step::prepare('sync the settings', $this->steps->quick(fn () => $primary->driver()->config()->sync())),
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [$this->applyStepOn($node)], failover: false),
            Step::prepare('check for a restart', $this->steps->quick(fn (DatabaseFlow $flow) => $this->checkRestartOn($flow, $primary))),
            ...($flow->context->restart ? $this->rolling->restart($flow) : []),
            Step::finish('refresh the status', $this->steps->quick(fn () => $this->refresh->handle($database))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }

    private function applyStepOn(DatabaseNode $node): Step
    {
        return Step::prepare("apply the settings on {$node->server->name}", $this->steps->quick(fn () => $node->driver()->config()->apply()));
    }

    private function checkRestartOn(DatabaseFlow $flow, DatabaseNode $primary): void
    {
        $pendingRestart = $primary->driver()->config()->pendingRestart();

        $flow->update(['context' => $flow->context->needingRestart($pendingRestart !== [])]);
    }
}
