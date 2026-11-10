<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Nodes\BuildNodeRequest;
use App\Actions\Databases\Recovery\RefreshRecoveryStatus;
use App\Contracts\Engines\Flow;
use App\Engines\EngineClient;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;

final readonly class ArchiveFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private EngineClient $engine,
        private BuildNodeRequest $nodeRequest,
        private RefreshRecoveryStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return $database->pointInTimeRecovery === null ? 'Turn off point-in-time recovery' : 'Update point-in-time recovery';
    }

    public function steps(DatabaseFlow $flow): array
    {
        $database = $flow->database;

        return [
            ...$database->nodesWithServers()->toBase()->map(fn (DatabaseNode $node): Step => $this->configureStepFor($database, $node))->all(),
            Step::finish('take a first backup', $this->steps->quick(fn () => $this->backUp($database))),
        ];
    }

    private function configureStepFor(Database $database, DatabaseNode $node): Step
    {
        return Step::prepare("configure {$node->server->name}", $this->steps->quick(fn () => $this->engine->send($node, $this->nodeRequest->handle($database, $node), "{$node->server->name}: configuring the archive")));
    }

    private function backUp(Database $database): void
    {
        if ($database->pointInTimeRecovery === null) {
            return;
        }

        $this->refresh->handle($database->pointInTimeRecovery, backUpNow: true);
    }

    public function cleanup(DatabaseFlow $flow): void {}
}
