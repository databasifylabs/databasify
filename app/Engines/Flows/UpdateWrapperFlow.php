<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Contracts\Engines\Flow;
use App\Engines\Requests\HostWrapperUpdate;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;

final readonly class UpdateWrapperFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RollingSteps $rolling,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return 'Update the databasify command';
    }

    public function steps(DatabaseFlow $flow): array
    {
        $update = new HostWrapperUpdate($flow->database->wrapperImage());

        return $this->rolling->over($flow, fn (DatabaseNode $node): array => [
            Step::finish("update {$node->server->name}", $this->steps->send($node, $update, 'updating the databasify command')),
        ], failover: false);
    }

    public function cleanup(DatabaseFlow $flow): void {}
}
