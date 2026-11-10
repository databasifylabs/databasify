<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Nodes\InstallPinnedWrapper;
use App\Contracts\Engines\Flow;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use App\Services\Images\WrapperResolver;

final readonly class UpdateWrapperFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RollingSteps $rolling,
        private InstallPinnedWrapper $wrapper,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return 'Install databasify '.WrapperResolver::version();
    }

    public function steps(DatabaseFlow $flow): array
    {
        return [
            ...$this->rolling->over($flow, fn (DatabaseNode $node): array => [
                Step::finish("update {$node->server->name}", $this->steps->quick(fn () => $this->wrapper->handle($node))),
            ], failover: false),
            Step::finish('record the wrapper version', $this->steps->quick(fn () => $flow->database->recordPinnedWrapper())),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void {}
}
