<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Servers\ReadServerFacts;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;
use App\Services\Databases\RollingNodes;

final readonly class RollOutNodeTuning
{
    public function __construct(
        private ReadServerFacts $facts,
        private BuildNodeConfig $nodeConfig,
        private RollingNodes $rolling,
        private EngineTasks $tasks,
    ) {}

    public function handle(DatabaseNode $node, string $run): void
    {
        $restarted = $this->tasks->started($node, $run, 'ready');

        if (! $restarted) {
            $this->facts->handle($node->server);
            $node->driver()->nodes()->configure($this->nodeConfig->handle($node->database, $node));
            $changed = $node->driver()->config()->tune() !== [];
            $node->driver()->config()->apply();

            if (! $changed) {
                $node->markReady();

                return;
            }

            $node->driver()->nodes()->restart();
        }

        $this->rolling->backOnline($node, $run, expectPrimary: false);
        $node->markReady();
    }
}
