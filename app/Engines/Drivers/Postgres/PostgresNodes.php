<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\DTOs\Databases\NodeStatus;
use App\Engines\Contracts\Nodes;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Grant;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\NodeMetrics;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;
use App\Engines\Drivers\TlsPair;
use App\Engines\Payload;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineUnsupported;

final readonly class PostgresNodes implements Nodes
{
    private const int LIFECYCLE_TIMEOUT = 300;

    public function __construct(
        private PostgresDriver $driver,
        private PostgresConfiguration $configuration,
    ) {}

    public function configure(NodeConfig $config): void
    {
        $this->configuration->configure($config);
    }

    public function start(): void
    {
        $this->driver->run('engines.postgres.nodes.start', ['units' => PostgresManifest::unitsOf($this->configuration->savedRequestOf())], timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function restart(): void
    {
        $unit = $this->configuration->savedRequestOf()->clustered ? PostgresManifest::PATRONI_UNIT : PostgresManifest::SERVER_UNIT;
        $this->driver->run('engines.postgres.nodes.restart', ['unit' => $unit], timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function wake(bool $all): void
    {
        $saved = $this->configuration->savedRequestOf();
        $this->driver->run('engines.postgres.nodes.wake', ['etcdOnly' => $saved->clustered && ! $all, 'units' => PostgresManifest::unitsOf($saved)], timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function reset(int $peers, array $hosts): void
    {
        throw EngineUnsupported::command('node reset');
    }

    public function bootstrap(Secrets $secrets, ?DefaultAccount $account): void
    {
        $saved = $this->configuration->savedRequestOf();

        if ($saved->clustered && ($saved->readOnly || $saved->primary !== $saved->ip)) {
            return;
        }

        $this->driver->query('engines.postgres.nodes.bootstrap', [
            'passwords' => PostgresFiles::passwordsOf($secrets),
            'account' => $account,
            'grants' => $account === null ? [] : [new Grant($account->database, GrantAccess::Write)],
            'managed' => [],
            'allowlist' => PostgresExtensionCatalog::allowlistFor($this->driver->node->database),
        ]);
    }

    public function installTls(string $certificate, string $key, string $ca): void
    {
        TlsPair::checkPair($certificate, $key, $ca);
        $this->driver->run('engines.postgres.nodes.tls-install', ['certificate' => $certificate, 'key' => $key, 'ca' => $ca]);
    }

    public function ready(string $key, bool $expectPrimary): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->driver->task($key, 'engines.postgres.nodes.ready', ['saved' => $saved, 'expectPrimary' => $expectPrimary, 'etcdEndpoints' => PostgresFiles::etcdEndpointsOf($saved)]);
    }

    public function status(): NodeStatus
    {
        $reply = $this->driver->read('engines.postgres.nodes.status');
        $patroni = $reply->nullableObject('patroni');
        $server = $reply->nullableObject('server');
        $role = $patroni === null ? $this->roleOfServer($server) : $this->roleOfPatroni($patroni);

        if ($role === NodeRole::Offline || $server === null) {
            return NodeStatus::reported(NodeRole::Offline, null);
        }

        return NodeStatus::reported($role, $server->string('version'));
    }

    public function metrics(): NodeMetrics
    {
        return NodeMetrics::fromPayload($this->driver->queryJson('engines.postgres.nodes.metrics'));
    }

    private function roleOfServer(?Payload $server): NodeRole
    {
        if ($server === null) {
            return NodeRole::Offline;
        }

        return $server->bool('recovery') ? NodeRole::Replica : NodeRole::Primary;
    }

    private function roleOfPatroni(Payload $patroni): NodeRole
    {
        return match ($patroni->has('role') ? $patroni->string('role') : '') {
            '' => NodeRole::Offline,
            'master', 'primary' => NodeRole::Primary,
            'replica', 'standby_leader' => NodeRole::Replica,
            default => NodeRole::Unknown,
        };
    }
}
