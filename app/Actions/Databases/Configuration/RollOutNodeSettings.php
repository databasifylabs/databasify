<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;
use App\Services\Databases\RollingNodes;

final readonly class RollOutNodeSettings
{
    public function __construct(
        private BuildNodeConfig $nodeConfig,
        private RollingNodes $rolling,
        private EngineTasks $tasks,
    ) {}

    public function handle(DatabaseNode $node, string $run): void
    {
        $node->driver()->nodes()->configure($this->nodeConfig->handle($node->database, $node));
        $node->driver()->config()->apply();
        $restarted = $this->tasks->started($node, $run, 'ready');

        if ($restarted || $node->driver()->config()->pendingRestart() !== []) {
            if (! $restarted) {
                $node->driver()->nodes()->restart();
            }

            $this->rolling->backOnline($node, $run, expectPrimary: false);
        }

        $node->markReady();
    }
}
