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
            ...$database->nodes()->with('server')->orderBy('position')->get()->toBase()
                ->map(fn (DatabaseNode $node): Step => Step::prepare("configure {$node->server->name}", $this->steps->quick(fn () => $this->engine->send($node, $this->nodeRequest->handle($database, $node), "{$node->server->name}: configuring the archive"))))
                ->all(),
            Step::finish('take a first backup', $this->steps->quick(fn () => $database->pointInTimeRecovery === null ? null : $this->refresh->handle($database->pointInTimeRecovery, backUpNow: true))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void {}
}
