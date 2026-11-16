<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\DTOs\Databases\NodeStatus;
use App\Engines\Contracts\Nodes;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\NodeMetrics;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;
use App\Engines\Drivers\TlsPair;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\EngineUnavailable;

final readonly class SentinelNodes implements Nodes
{
    private const int LIFECYCLE_TIMEOUT = 300;

    public function __construct(
        private SentinelDriver $driver,
        private SentinelConfiguration $configuration,
    ) {}

    public function configure(NodeConfig $config): void
    {
        $this->configuration->configure($config);
    }

    public function start(): void
    {
        $request = $this->configuration->savedRequestOf();

        $this->driver->run('engines.sentinel.nodes.start', [
            'holds' => $request->clustered && ! $request->readOnly,
            'persistent' => SentinelFiles::persistent($request->settings),
        ], timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function wake(bool $all): void
    {
        $this->driver->run('engines.sentinel.nodes.wake', timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function restart(): void
    {
        try {
            $effective = $this->configuration->effectiveOf($this->configuration->savedRequestOf());
            $keys = $this->configuration->restartOnlyKeys();
        } catch (EngineBusy) {
            $effective = [];
            $keys = [];
        }

        $this->driver->run('engines.sentinel.nodes.restart', [
            'names' => array_map(SentinelFiles::configKeyOf(...), $keys),
            'lines' => collect($effective)->only($keys)->map(fn (string $value, string $key): string => SentinelFiles::directiveOf($key, $value))->values()->all(),
        ], timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function reset(int $peers, array $hosts): void
    {
        $this->driver->run('engines.sentinel.nodes.reset', ['peers' => $peers, 'keep' => ['127.0.0.1', ...$hosts]], timeout: 180);
    }

    public function bootstrap(Secrets $secrets, ?DefaultAccount $account): void {}

    public function installTls(string $certificate, string $key, string $ca): void
    {
        TlsPair::checkPair($certificate, $key, $ca);
        $this->driver->run('engines.sentinel.nodes.tls-install', ['pem' => rtrim($certificate, "\n")."\n".$key, 'ca' => $ca]);
    }

    public function ready(string $key, bool $expectPrimary): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->driver->task($key, 'engines.sentinel.nodes.ready', [
            'clustered' => $saved->clustered,
            'readOnly' => $saved->readOnly,
            'self' => $saved->ip,
        ]);
    }

    public function status(): NodeStatus
    {
        try {
            $reply = $this->driver->read('engines.sentinel.nodes.status');
        } catch (EngineUnavailable) {
            return NodeStatus::reported(NodeRole::Offline, null);
        }

        $replication = new SentinelReply($reply->string('replication'));
        $version = (new SentinelReply($reply->string('server')))->fieldOf($this->driver::flavor()->versionField);

        return NodeStatus::reported($this->roleIn($replication), $version === '' ? null : $version);
    }

    public function metrics(): NodeMetrics
    {
        $info = new SentinelReply($this->driver->read('engines.sentinel.nodes.metrics')->string('info'));

        return new NodeMetrics($info->intOf('connected_clients') ?? 0, $info->intOf('instantaneous_ops_per_sec') ?? 0, $info->megabytesOf('used_memory') ?? 0);
    }

    private function roleIn(SentinelReply $replication): NodeRole
    {
        return match ($replication->fieldOf('role')) {
            'master' => NodeRole::Primary,
            'slave' => $replication->fieldOf('master_link_status') === 'up' && $replication->fieldOf('master_sync_in_progress') === '0' ? NodeRole::Replica : NodeRole::Unknown,
            default => NodeRole::Unknown,
        };
    }
}
