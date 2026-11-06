<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Failover\PromoteStandby;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;
use RuntimeException;

final readonly class RebootNodes
{
    public function __construct(
        private NodeRunner $nodeRunner,
        private RefreshDatabaseStatus $refresh,
        private PromoteStandby $promote,
        private RebootDatabaseNode $reboot,
    ) {}

    public function handle(Database $database): void
    {
        $this->ensureHealthy($database);
        $primary = $database->primaryOrSeed();
        $nodes = $database->nodesWithServers();
        $this->nodeRunner->inGroupsOf(
            $nodes->readOnly(),
            fn (DatabaseNode $node) => $this->reboot->handle($database, $node, expectPrimary: false),
            'rebooting read-only nodes',
        );
        $nodes->standbysBesides($primary)->each(fn (DatabaseNode $node) => $this->reboot->handle($database, $node, expectPrimary: false));
        $this->rebootPrimary($database, $primary);
        $this->refresh->handle($database);
    }

    private function ensureHealthy(Database $database): void
    {
        $status = $this->refresh->handle($database);

        if ($status === DatabaseStatus::Ready || ! $database->hasStandby()) {
            return;
        }

        throw new RuntimeException("{$database->name} is {$status->value}, bring every node back before rebooting its servers one by one.");
    }

    private function rebootPrimary(Database $database, DatabaseNode $primary): void
    {
        if (! $database->hasStandby()) {
            $this->reboot->handle($database, $primary, expectPrimary: true);

            return;
        }

        $this->promote->handle($database);
        $this->reboot->handle($database, $primary->refresh(), expectPrimary: false);
    }
}
