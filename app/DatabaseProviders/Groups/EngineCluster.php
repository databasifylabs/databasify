<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Groups;

use App\DTOs\Databases\QuorumCount;
use App\Enums\Databases\NodeRole;
use App\Models\DatabaseNode;
use App\Services\Ssh\Ssh;
use App\Support\Ssh\KeyValueOutput;

readonly class EngineCluster extends EngineGroup
{
    private const int UNKNOWN_POSITION = -1;

    public function cluster(DatabaseNode $node): void
    {
        $this->runStep($node, 'cluster.cluster', $this->scriptData()->clustering($node), "{$node->server->name}: turning on clustering", Ssh::LONG_TIMEOUT);
    }

    public function uncluster(DatabaseNode $node): void
    {
        $this->runStep($node, 'cluster.uncluster', $this->scriptData()->clustering($node), "{$node->server->name}: turning off clustering");
    }

    public function failoverTo(DatabaseNode $primary, DatabaseNode $target): void
    {
        $this->runStep($primary, 'cluster.failover', [
            ...$this->scriptData()->for(),
            'target' => $this->engine->clientAddress($target),
        ], "moving the primary off {$primary->server->name}");
    }

    public function forceQuorum(DatabaseNode $survivor, bool $othersAreDown, bool $survivorSeeds): void
    {
        $this->runStep($survivor, 'cluster.force-quorum', [
            ...$this->scriptData()->for($survivor),
            'instance' => $this->engine->clientAddress($survivor),
            'othersAreDown' => $othersAreDown,
        ], $this->forcingQuorumTitleFor($survivor, $survivorSeeds));
    }

    public function quorum(DatabaseNode $node): QuorumCount
    {
        $output = KeyValueOutput::parse($this->runStep($node, 'cluster.quorum', $this->scriptData()->for($node)));

        return new QuorumCount((int) ($output['total'] ?? 0), (int) ($output['online'] ?? 0));
    }

    public function admit(DatabaseNode $node): void
    {
        $this->makeRoomFor($node, 'cluster.admit', $this->cloneDonorFor($node));
    }

    public function adopt(DatabaseNode $node): void
    {
        $this->makeRoomFor($node, 'cluster.adopt');
    }

    public function cloneDonorFor(DatabaseNode $joining): ?DatabaseNode
    {
        return null;
    }

    public function membershipChangesFor(DatabaseNode $joining): bool
    {
        return true;
    }

    public function updateMembership(DatabaseNode $node): void
    {
        $this->runStep($node, 'cluster.membership', [...$this->scriptData()->for(), ...$this->engine->membershipVariables()], "{$node->server->name}: updating membership");
    }

    public function forget(DatabaseNode $primary, DatabaseNode $node): void
    {
        $this->runStep($primary, 'cluster.forget', [
            ...$this->scriptData()->for(),
            'instance' => $this->engine->clientAddress($node),
        ], "{$primary->server->name}: removing {$node->server->name} from the cluster");
    }

    public function rejoin(DatabaseNode $node): void
    {
        $this->runStep($node, 'cluster.rejoin', $this->scriptData()->waking($node), "{$node->server->name}: starting the engine so it rejoins");
    }

    public function recoveryPosition(DatabaseNode $node): ?int
    {
        $output = $this->runStep($node, 'cluster.recovery-position', $this->scriptData()->for($node), "{$node->server->name}: reading its last committed position");

        return (int) (KeyValueOutput::parse($output)['position'] ?? self::UNKNOWN_POSITION);
    }

    protected function healthyStandbyOtherThan(DatabaseNode $joining): ?DatabaseNode
    {
        return $this->database->nodesWithServers()
            ->first(fn (DatabaseNode $node): bool => $node->isMember() && $node->role === NodeRole::Replica && ! $node->is($joining));
    }

    private function forcingQuorumTitleFor(DatabaseNode $survivor, bool $survivorSeeds): string
    {
        if ($survivorSeeds) {
            return "forcing quorum on {$survivor->server->name}, the unreachable members rejoin when they come back";
        }

        return "forcing quorum from {$survivor->server->name}, the most up-to-date member seeds the cluster and the unreachable members rejoin when they come back";
    }

    private function makeRoomFor(DatabaseNode $node, string $script, ?DatabaseNode $donor = null): void
    {
        $primary = $donor ?? $this->database->primaryOrSeed();

        if ($primary->is($node)) {
            return;
        }

        $this->runStep($primary, $script, $this->scriptData()->admission($node), "{$primary->server->name}: making room for {$node->server->name}", Ssh::LONG_TIMEOUT);
    }
}
