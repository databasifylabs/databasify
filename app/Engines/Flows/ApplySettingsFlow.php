<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\BuildNodeRequest;
use App\Contracts\Engines\Flow;
use App\Engines\EngineClient;
use App\Engines\Requests\ConfigApply;
use App\Engines\Requests\ConfigShow;
use App\Engines\Requests\ConfigSync;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;

final readonly class ApplySettingsFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RollingSteps $rolling,
        private EngineClient $engine,
        private BuildNodeRequest $nodeRequest,
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
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [$this->configureStepOn($database, $node)], failover: false),
            Step::prepare('sync the settings', $this->steps->send($primary, new ConfigSync, 'syncing the settings')),
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

    private function configureStepOn(Database $database, DatabaseNode $node): Step
    {
        return Step::prepare("configure {$node->server->name}", $this->steps->quick(
            fn () => $this->engine->send($node, $this->nodeRequest->handle($database, $node), "{$node->server->name}: configuring the node"),
        ));
    }

    private function applyStepOn(DatabaseNode $node): Step
    {
        return Step::prepare("apply the settings on {$node->server->name}", $this->steps->send($node, new ConfigApply, 'applying the settings'));
    }

    private function checkRestartOn(DatabaseFlow $flow, DatabaseNode $primary): void
    {
        $pendingRestart = $this->engine->send($primary, new ConfigShow, "{$primary->server->name}: reading the live settings")->pendingRestart;

        $flow->update(['context' => $flow->context->needingRestart($pendingRestart !== [])]);
    }
}
