<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Network\RemoveNodeFirewall;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Engines\Runtime\Runtime;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Queues\QueueGroup;
use App\Exceptions\Engines\EngineUnsupported;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(7200)]
#[DeleteWhenMissingModels]
final class RemoveNodeJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(
        public Database $database,
        public DatabaseNode $node,
        public ?DatabaseNode $standby,
        public bool $force,
    ) {}

    public function handle(
        BuildNodeConfig $nodeConfig,
        ClearNodeDns $dns,
        Runtime $runtime,
        RemoveNodeFirewall $removeFirewall,
        ApplyNodeFirewall $firewall,
        RefreshDatabaseStatus $refresh,
    ): void {
        $database = $this->database;
        $leaving = $this->node->load('server');
        $remaining = $database->nodesOtherThan($leaving)->load('server')->sortBy('position')->values();

        if ($this->standby !== null) {
            $leaving->driver()->cluster()->failover($this->standby->privateIp());
        }

        $this->clearDnsOf($database, $leaving, $dns);
        $this->takeOutOfCluster($leaving);

        $database->ignoringStop(function () use ($database, $leaving, $remaining, $nodeConfig, $runtime, $removeFirewall, $firewall, $refresh): void {
            $remaining->each(function (DatabaseNode $node) use ($database, $leaving, $remaining, $nodeConfig): void {
                $node->driver()->nodes()->configure($nodeConfig->handle($database, $node, without: $leaving));
                $this->forgetOn($node, $leaving, $remaining);
                $node->driver()->cluster()->membership($nodeConfig->membersOf($database, without: $leaving));
            });
            $this->erase($database, $leaving, $runtime, $removeFirewall);
            $leaving->delete();
            $database->forgetNodeCertificates($leaving);
            $remaining->each(fn (DatabaseNode $peer) => rescue(fn () => $firewall->handle($database, $peer, $database->trustedCidrs())));
            $database->matchModeToMembers();
            $refresh->handle($database);
        });
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Scaling;
    }

    protected function operationName(): string
    {
        return "Remove {$this->node->displayName()}";
    }

    private function takeOutOfCluster(DatabaseNode $leaving): void
    {
        try {
            $leaving->driver()->cluster()->disable();
        } catch (Throwable $exception) {
            throw_unless($this->force, $exception);
            report($exception);
        }
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

    private function clearDnsOf(Database $database, DatabaseNode $node, ClearNodeDns $dns): void
    {
        try {
            $dns->handle($node);
        } catch (Throwable $exception) {
            $node->server->logFailure('Remove DNS records', "Could not remove the Cloudflare records of {$node->server->name}, delete them by hand: {$exception->getMessage()}", $database);
        }
    }

    private function erase(Database $database, DatabaseNode $node, Runtime $runtime, RemoveNodeFirewall $removeFirewall): void
    {
        try {
            $runtime->remove($node, erase: true);
            $removeFirewall->handle($database, $node);
        } catch (Throwable $exception) {
            $node->server->logFailure("{$node->server->name}: removing it from {$database->name}", "{$node->server->name} was removed; its server could not be reached, so its data was not erased: {$exception->getMessage()} Wipe or destroy {$node->server->name}.", $database);
        }
    }
}
