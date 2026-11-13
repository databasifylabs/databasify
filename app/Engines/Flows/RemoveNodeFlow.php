<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Network\RemoveNodeFirewall;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Contracts\Engines\Flow;
use App\Engines\Runtime\Runtime;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Closure;
use Throwable;

final readonly class RemoveNodeFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private Runtime $runtime,
        private BuildNodeConfig $nodeConfig,
        private ApplyNodeFirewall $firewall,
        private RemoveNodeFirewall $removeFirewall,
        private ClearNodeDns $dns,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        $node = $context->targetNodeId === null ? null : $database->nodes()->find($context->targetNodeId);

        return $node === null ? 'Remove node' : "Remove {$node->displayName()}";
    }

    public function steps(DatabaseFlow $flow): array
    {
        $database = $flow->database;
        $leaving = $database->nodes()->with('server')->find($flow->context->targetNodeId);

        if ($leaving === null) {
            return [];
        }

        $remaining = $database->nodesOtherThan($leaving)->load('server')->sortBy('position')->values();
        $standby = $flow->context->failoverTo === null ? null : $remaining->firstWhere('id', $flow->context->failoverTo);
        $force = $flow->context->force;

        return [
            ...$this->failoverStepsOf($leaving, $standby),
            Step::prepare('take the node out of DNS', $this->steps->quick(fn () => $this->clearDnsOf($database, $leaving))),
            Step::prepare('take the node out of the cluster', $this->tolerating($force, $this->steps->quick(fn () => $leaving->driver()->cluster()->disable()))),
            ...$remaining->toBase()->flatMap(fn (DatabaseNode $node): array => $this->forgetStepsOn($database, $node, $leaving, $remaining))->all(),
            Step::finish('erase the node', $this->steps->quick(fn () => $this->erase($database, $leaving))),
            Step::finish('forget the node', $this->steps->quick(fn () => $this->forget($database, $leaving, $remaining))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }

    /**
     * @return list<Step>
     */
    private function forgetStepsOn(Database $database, DatabaseNode $node, DatabaseNode $leaving, DatabaseNodeCollection $remaining): array
    {
        $server = $node->server->name;

        return [
            Step::finish("configure {$server}", $this->steps->quick(fn () => $this->configureWithout($database, $node, $leaving))),
            Step::finish("forget the node on {$server}", $this->steps->quick(fn () => $this->forgetOn($node, $leaving, $remaining))),
            Step::finish("update the members on {$server}", $this->steps->quick(fn () => $this->updateMembersWithout($database, $node, $leaving))),
        ];
    }

    /**
     * @return list<Step>
     */
    private function failoverStepsOf(DatabaseNode $leaving, ?DatabaseNode $standby): array
    {
        if ($standby === null) {
            return [];
        }

        return [Step::prepare('hand the primary to a standby', $this->steps->quick(fn () => $leaving->driver()->cluster()->failover($standby->privateIp())))];
    }

    private function configureWithout(Database $database, DatabaseNode $node, DatabaseNode $leaving): void
    {
        $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node, without: $leaving));
    }

    private function updateMembersWithout(Database $database, DatabaseNode $node, DatabaseNode $leaving): void
    {
        $node->driver()->cluster()->membership($this->nodeConfig->membersOf($database, without: $leaving));
    }

    private function forgetOn(DatabaseNode $node, DatabaseNode $leaving, DatabaseNodeCollection $remaining): void
    {
        try {
            $node->driver()->cluster()->forget($leaving->privateIp());
        } catch (EngineUnsupported) {
            $hosts = array_values($remaining->toBase()->map(fn (DatabaseNode $peer): string => $peer->privateIp())->all());
            $node->driver()->nodes()->reset($remaining->count() - 1, $hosts);
        }
    }

    private function clearDnsOf(Database $database, DatabaseNode $node): void
    {
        try {
            $this->dns->handle($node);
        } catch (Throwable $exception) {
            $node->server->logFailure('Remove DNS records', "Could not remove the Cloudflare records of {$node->server->name}, delete them by hand: {$exception->getMessage()}", $database);
        }
    }

    private function erase(Database $database, DatabaseNode $node): void
    {
        try {
            $this->runtime->remove($node, erase: true);
            $this->removeFirewall->handle($database, $node);
        } catch (Throwable $exception) {
            $node->server->logFailure("{$node->server->name}: removing it from {$database->name}", "{$node->server->name} was removed; its server could not be reached, so its data was not erased: {$exception->getMessage()} Wipe or destroy {$node->server->name}.", $database);
        }
    }

    private function forget(Database $database, DatabaseNode $node, DatabaseNodeCollection $remaining): void
    {
        $node->delete();
        $database->forgetNodeCertificates($node);
        $remaining->each(fn (DatabaseNode $peer) => rescue(fn () => $this->firewall->handle($database, $peer, $database->trustedCidrs())));
        $database->matchModeToMembers();
        $this->refresh->handle($database);
    }

    /**
     * @param  Closure(DatabaseFlow): null  $step
     * @return Closure(DatabaseFlow): null
     */
    private function tolerating(bool $force, Closure $step): Closure
    {
        return function (DatabaseFlow $flow) use ($force, $step): null {
            try {
                return $step($flow);
            } catch (Throwable $exception) {
                throw_unless($force, $exception);
                report($exception);

                return null;
            }
        };
    }
}
