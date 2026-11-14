<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;
use App\Services\Databases\RollingNodes;

final readonly class RebootNodeServer
{
    public function __construct(
        private RebootServer $reboot,
        private RollingNodes $rolling,
        private EngineTasks $tasks,
    ) {}

    public function handle(DatabaseNode $node, string $run): void
    {
        if ($this->tasks->started($node, $run, 'ready')) {
            $this->rolling->backOnline($node, $run, expectPrimary: false);
        } else {
            $this->reboot->handle($node, $run, expectPrimary: false);
        }

        $node->markReady();
    }
}
