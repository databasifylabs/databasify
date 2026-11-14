<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Network\RemoveNodeFirewall;
use App\Engines\Runtime\Runtime;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\Database;
use App\Models\DatabaseNode;
use Throwable;

final readonly class TakeNodeOut
{
    public function __construct(
        private BuildNodeConfig $nodeConfig,
        private ClearNodeDns $dns,
        private Runtime $runtime,
        private RemoveNodeFirewall $removeFirewall,
        private ApplyNodeFirewall $firewall,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database, DatabaseNode $node, ?DatabaseNode $standby, bool $force): void
    {
        $leaving = $node->load('server');
        $remaining = $database->nodesOtherThan($leaving)->load('server')->sortBy('position')->values();

        if ($standby !== null) {
            $leaving->driver()->cluster()->failover($standby->privateIp());
        }

        try {
            $this->dns->handle($leaving);
        } catch (Throwable $exception) {
            $leaving->server->logFailure('Remove DNS records', "Could not remove the Cloudflare records of {$leaving->server->name}, delete them by hand: {$exception->getMessage()}", $database);
        }

        try {
            $leaving->driver()->cluster()->disable();
        } catch (Throwable $exception) {
            throw_unless($force, $exception);
            report($exception);
        }

        $hosts = array_values($remaining->toBase()->map(fn (DatabaseNode $peer): string => $peer->privateIp())->all());

        foreach ($remaining as $node) {
            $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node, without: $leaving));

            try {
                $node->driver()->cluster()->forget($leaving->privateIp());
            } catch (EngineUnsupported) {
                if ($remaining->hasSole()) {
                    $node->driver()->cluster()->disable();
                } else {
                    $node->driver()->nodes()->reset($remaining->count() - 1, $hosts);
                }
            }

            $node->driver()->cluster()->membership($this->nodeConfig->membersOf($database, without: $leaving));
        }

        try {
            $this->runtime->remove($leaving, erase: true);
            $this->removeFirewall->handle($database, $leaving);
        } catch (Throwable $exception) {
            $leaving->server->logFailure("{$leaving->server->name}: removing it from {$database->name}", "{$leaving->server->name} was removed; its server could not be reached, so its data was not erased: {$exception->getMessage()} Wipe or destroy {$leaving->server->name}.", $database);
        }

        $leaving->delete();
        $database->forgetNodeCertificates($leaving);
        $remaining->each(fn (DatabaseNode $peer) => rescue(fn () => $this->firewall->handle($database, $peer, $peer->trustedCidrs())));
        $database->matchModeToMembers();
        $this->refresh->handle($database);
        $database->markReady();
    }
}
