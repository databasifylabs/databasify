<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\BuildNodeRequest;
use App\Actions\Servers\ReadServerFacts;
use App\Contracts\Engines\Flow;
use App\Engines\EngineClient;
use App\Engines\Requests\ConfigApply;
use App\Engines\Requests\ConfigTune;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;

final readonly class RetuneFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RollingSteps $rolling,
        private EngineClient $engine,
        private BuildNodeRequest $nodeRequest,
        private ReadServerFacts $facts,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return 'Retune for server size';
    }

    public function steps(DatabaseFlow $flow): array
    {
        $database = $flow->database;

        return [
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [Step::prepare("read the size of {$node->server->name}", $this->steps->quick(fn () => $this->facts->handle($node->server)))], failover: false),
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [Step::prepare("configure {$node->server->name}", $this->steps->quick(fn () => $this->engine->send($node, $this->nodeRequest->handle($database, $node), "{$node->server->name}: configuring the node")))], failover: false),
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [
                Step::prepare("tune {$node->server->name}", $this->steps->quick(fn (DatabaseFlow $flow) => $this->tune($flow, $node))),
                Step::prepare("apply the tuning on {$node->server->name}", $this->steps->send($node, new ConfigApply, 'applying the tuned settings')),
            ], failover: false),
            ...($flow->context->restart ? $this->rolling->restart($flow) : []),
            Step::finish('refresh the status', $this->steps->quick(function () use ($database): void {
                $database->markRetuned();
                $this->refresh->handle($database);
            })),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }

    private function tune(DatabaseFlow $flow, DatabaseNode $node): void
    {
        $restart = $this->engine->send($node, new ConfigTune, "{$node->server->name}: tuning the engine")->restart !== [];

        if ($restart && ! $flow->context->restart) {
            $flow->update(['context' => $flow->context->needingRestart(true)]);
        }
    }
}
