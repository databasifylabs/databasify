<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
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
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [$this->readSizeStepOn($node)], failover: false),
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [$this->steps->configure($database, $node)], failover: false),
            ...$this->rolling->over($flow, $this->tuneStepsOn(...), failover: false),
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

    private function readSizeStepOn(DatabaseNode $node): Step
    {
        return Step::prepare("read the size of {$node->server->name}", $this->steps->quick(fn () => $this->facts->handle($node->server)));
    }

    /**
     * @return list<Step>
     */
    private function tuneStepsOn(DatabaseNode $node): array
    {
        return [
            Step::prepare("tune {$node->server->name}", $this->steps->quick(fn (DatabaseFlow $flow) => $this->tune($flow, $node))),
            Step::prepare("apply the tuning on {$node->server->name}", $this->steps->send($node, new ConfigApply, 'applying the tuned settings')),
        ];
    }

    private function tune(DatabaseFlow $flow, DatabaseNode $node): void
    {
        $restartKeys = $this->engine->send($node, new ConfigTune, "{$node->server->name}: tuning the engine")->restart;

        if ($restartKeys === [] || $flow->context->restart) {
            return;
        }

        $flow->update(['context' => $flow->context->needingRestart(true)]);
    }
}
