<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Actions\Databases\Domains\EnsureHostnamesFree;
use App\Actions\Databases\Flows\StartFlow;
use App\DTOs\Dns\HostPair;
use App\Engines\Flows\RedeployNodeFlow;
use App\Engines\Flows\RollingSteps;
use App\Models\DatabaseNode;

final readonly class RenameDatabaseNode
{
    public function __construct(
        private EnsureHostnamesFree $free,
        private ClearNodeDns $clear,
        private StartFlow $startFlow,
        private RedeployNodeFlow $flow,
    ) {}

    public function handle(DatabaseNode $node, string $name): void
    {
        $database = $node->database;
        $zone = $database->dnsZone;

        if ($zone === null) {
            $node->update(['name' => $name]);

            return;
        }

        $database->ensureIdle();
        $this->free->handle($zone, HostPair::under($zone, $name)->all(), 'name');
        rescue(fn () => $this->clear->handle($node), report: false);
        $node->rename($name);
        $this->startFlow->handle($database, $this->flow, RollingSteps::contextFor($database, only: $node));
    }
}
