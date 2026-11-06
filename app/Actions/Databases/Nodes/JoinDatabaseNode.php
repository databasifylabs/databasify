<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Certificates\IssueCertificates;
use App\Actions\Databases\Certificates\IssueNodeCertificate;
use App\Actions\Databases\Certificates\ReloadNodeCertificates;
use App\Actions\Databases\Lifecycle\RefreshNodeStatuses;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;
use Closure;
use Throwable;

final readonly class JoinDatabaseNode
{
    private const int FIRST_CLUSTER_SIZE = 2;

    public function __construct(
        private ClusterDatabase $cluster,
        private DeployDatabaseNode $deploy,
        private ApplyNodeFirewall $firewall,
        private UpdateDatabaseMembership $membership,
        private SettleFailedJoin $settle,
        private IssueCertificates $certificates,
        private IssueNodeCertificate $nodeCertificate,
        private ReloadNodeCertificates $reloadCertificates,
        private RefreshNodeStatuses $refreshNodes,
        private PullEngineImage $pull,
        private NodeRunner $nodeRunner,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        try {
            $this->issueCertificatesFor($database, $node);
            $this->openPeerPortsTo($database, $node);
            $this->clusterOnFirstPeer($database, $node);
            $this->deploy->handle($database, $node, fresh: true, joining: true);
            $this->updateMembershipFor($database, $node);
            $this->reloadMemberCertificates($database, $node);
            $database->matchModeToMembers();
            $this->refreshNodes->handle($database, $this->changedBy($database, $node));
        } catch (Throwable $exception) {
            $this->settle->handle($database, $node, $exception);
        }
    }

    private function updateMembershipFor(Database $database, DatabaseNode $node): void
    {
        if (! $database->provider()->cluster()->membershipChangesFor($node)) {
            return;
        }

        $this->membership->handle($database, except: $node);
    }

    private function changedBy(Database $database, DatabaseNode $node): DatabaseNodeCollection
    {
        $nodes = $database->nodesWithServers();

        if ($node->isReadOnly()) {
            return $nodes->filter(fn (DatabaseNode $candidate): bool => $candidate->is($node) || $candidate->is($database->primaryOrSeed()));
        }

        return $nodes->filter(fn (DatabaseNode $candidate): bool => $candidate->isMember() || $candidate->is($node));
    }

    private function clusterOnFirstPeer(Database $database, DatabaseNode $node): void
    {
        if ($database->size() === self::FIRST_CLUSTER_SIZE) {
            $this->cluster->handle($database, $node);
        }
    }

    private function openPeerPortsTo(Database $database, DatabaseNode $node): void
    {
        $sources = $database->trustedCidrs();
        $others = $database->nodesOtherThan($node);
        $tasks = $others->mapWithKeys(fn (DatabaseNode $member): array => [$member->id => $this->peerPortsTaskOn($database, $member, $sources)])->all();

        $this->nodeRunner->run([...$tasks, 'pull' => $this->pullTaskOn($database, $node)], "opening peer ports on {$others->count()} nodes");
    }

    /**
     * @param  array<int, string>  $sources
     */
    private function peerPortsTaskOn(Database $database, DatabaseNode $member, array $sources): Closure
    {
        return fn () => $this->firewall->handle($database, $member, $sources);
    }

    private function pullTaskOn(Database $database, DatabaseNode $node): Closure
    {
        return fn () => $this->pull->handle($database, $node, $database->image());
    }

    private function issueCertificatesFor(Database $database, DatabaseNode $node): void
    {
        if ($node->isReadOnly()) {
            $this->nodeCertificate->handle($database, $node);

            return;
        }

        $this->certificates->handle($database, reuseAuthority: true);
    }

    private function reloadMemberCertificates(Database $database, DatabaseNode $node): void
    {
        if ($node->isReadOnly()) {
            return;
        }

        $members = $database->membersOtherThan($node);
        $this->nodeRunner->each(
            $members,
            fn (DatabaseNode $member) => $this->reloadCertificates->handle($database, $member),
            "reloading certificates on {$members->count()} members",
        );
    }
}
