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

        try {
            $dns->handle($leaving);
        } catch (Throwable $exception) {
            $leaving->server->logFailure('Remove DNS records', "Could not remove the Cloudflare records of {$leaving->server->name}, delete them by hand: {$exception->getMessage()}", $database);
        }

        try {
            $leaving->driver()->cluster()->disable();
        } catch (Throwable $exception) {
            throw_unless($this->force, $exception);
            report($exception);
        }

        $database->ignoringStop(function () use ($database, $leaving, $remaining, $nodeConfig, $runtime, $removeFirewall, $firewall, $refresh): void {
            $hosts = array_values($remaining->toBase()->map(fn (DatabaseNode $peer): string => $peer->privateIp())->all());

            foreach ($remaining as $node) {
                $node->driver()->nodes()->configure($nodeConfig->handle($database, $node, without: $leaving));

                try {
                    $node->driver()->cluster()->forget($leaving->privateIp());
                } catch (EngineUnsupported) {
                    $node->driver()->nodes()->reset($remaining->count() - 1, $hosts);
                }

                $node->driver()->cluster()->membership($nodeConfig->membersOf($database, without: $leaving));
            }

            try {
                $runtime->remove($leaving, erase: true);
                $removeFirewall->handle($database, $leaving);
            } catch (Throwable $exception) {
                $leaving->server->logFailure("{$leaving->server->name}: removing it from {$database->name}", "{$leaving->server->name} was removed; its server could not be reached, so its data was not erased: {$exception->getMessage()} Wipe or destroy {$leaving->server->name}.", $database);
            }

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
}
