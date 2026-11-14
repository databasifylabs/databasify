<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Databases\NodeStatus;
use App\DTOs\Databases\QuorumCount;
use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\DTOs\Recovery\ArchiveStatus;
use App\Engines\Contracts\Accounts;
use App\Engines\Contracts\Archive;
use App\Engines\Contracts\Cluster;
use App\Engines\Contracts\Config;
use App\Engines\Contracts\Migration;
use App\Engines\Contracts\Nodes;
use App\Engines\Contracts\Pools;
use App\Engines\Data\AccountList;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\EngineFacts;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\NodeMetrics;
use App\Engines\Data\Operation;
use App\Engines\Data\Pool;
use App\Engines\Data\Secrets;
use App\Engines\Data\Storage;
use App\Engines\Drivers\AbstractEngineDriver;
use App\Engines\Drivers\Postgres\PostgresDriver;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\OperationState;
use Carbon\CarbonImmutable;

final readonly class RecordingDriver extends AbstractEngineDriver
{
    public static function facts(string $line): EngineFacts
    {
        return PostgresDriver::facts($line);
    }

    public static function running(string $key): Operation
    {
        return new Operation($key, 'task', OperationState::Running, '', null, null);
    }

    public function taskStatus(string $key): Operation
    {
        return $this->recorded('task.status', [$key], new Operation($key, 'task', OperationState::Succeeded, '', null, null));
    }

    public function stopTask(string $key): Operation
    {
        return $this->recorded('task.stop', [$key], new Operation($key, 'task', OperationState::Cancelled, '', null, null));
    }

    public function nodes(): Nodes
    {
        return new readonly class($this) implements Nodes
        {
            public function __construct(private RecordingDriver $driver) {}

            public function configure(NodeConfig $config): void
            {
                $this->driver->recorded('nodes.configure', [$config]);
            }

            public function start(): void
            {
                $this->driver->recorded('nodes.start', []);
            }

            public function restart(): void
            {
                $this->driver->recorded('nodes.restart', []);
            }

            public function wake(bool $all): void
            {
                $this->driver->recorded('nodes.wake', [$all]);
            }

            public function reset(int $peers, array $hosts): void
            {
                $this->driver->recorded('nodes.reset', [$peers, $hosts]);
            }

            public function bootstrap(Secrets $secrets, ?DefaultAccount $account): void
            {
                $this->driver->recorded('nodes.bootstrap', [$secrets, $account]);
            }

            public function installTls(string $certificate, string $key, string $ca): void
            {
                $this->driver->recorded('nodes.installTls', [$certificate, $key, $ca]);
            }

            public function ready(string $key, bool $expectPrimary): Operation
            {
                return $this->driver->recorded('nodes.ready', [$key, $expectPrimary], RecordingDriver::running($key));
            }

            public function status(): NodeStatus
            {
                return $this->driver->recorded('nodes.status', [], NodeStatus::reported(NodeRole::Primary, '1.0'));
            }

            public function metrics(): NodeMetrics
            {
                return $this->driver->recorded('nodes.metrics', [], new NodeMetrics(3, 120, 40));
            }
        };
    }

    public function cluster(): Cluster
    {
        return new readonly class($this) implements Cluster
        {
            public function __construct(private RecordingDriver $driver) {}

            public function enable(string $key): Operation
            {
                return $this->driver->recorded('cluster.enable', [$key], RecordingDriver::running($key));
            }

            public function disable(): void
            {
                $this->driver->recorded('cluster.disable', []);
            }

            public function failover(string $targetIp): void
            {
                $this->driver->recorded('cluster.failover', [$targetIp]);
            }

            public function forceQuorum(bool $othersDown): void
            {
                $this->driver->recorded('cluster.forceQuorum', [$othersDown]);
            }

            public function membership(array $members): void
            {
                $this->driver->recorded('cluster.membership', [$members]);
            }

            public function rejoin(string $key, bool $readOnly): Operation
            {
                return $this->driver->recorded('cluster.rejoin', [$key, $readOnly], RecordingDriver::running($key));
            }

            public function forget(string $ip): void
            {
                $this->driver->recorded('cluster.forget', [$ip]);
            }

            public function position(): ?int
            {
                return $this->driver->recorded('cluster.position', [], 0);
            }

            public function quorum(): QuorumCount
            {
                return $this->driver->recorded('cluster.quorum', [], new QuorumCount(3, 3));
            }
        };
    }

    public function config(): Config
    {
        return new readonly class($this) implements Config
        {
            public function __construct(private RecordingDriver $driver) {}

            public function apply(): void
            {
                $this->driver->recorded('config.apply', []);
            }

            public function sync(): void
            {
                $this->driver->recorded('config.sync', []);
            }

            public function pendingRestart(): array
            {
                return $this->driver->recorded('config.pendingRestart', [], []);
            }

            public function fields(): array
            {
                return $this->driver->recorded('config.fields', [], []);
            }

            public function tune(): array
            {
                return $this->driver->recorded('config.tune', [], []);
            }
        };
    }

    public function accounts(): Accounts
    {
        return new readonly class($this) implements Accounts
        {
            public function __construct(private RecordingDriver $driver) {}

            public function list(): AccountList
            {
                return $this->driver->recorded('accounts.list', [], new AccountList(['databasify'], ['defaultdb']));
            }

            public function createDatabase(string $name): void
            {
                $this->driver->recorded('accounts.createDatabase', [$name]);
            }

            public function dropDatabase(string $name): void
            {
                $this->driver->recorded('accounts.dropDatabase', [$name]);
            }

            public function createUser(string $name, string $password, array $grants, ?string $encryption): void
            {
                $this->driver->recorded('accounts.createUser', [$name, $password, $grants, $encryption]);
            }

            public function setPassword(string $name, string $password, ?string $encryption): void
            {
                $this->driver->recorded('accounts.setPassword', [$name, $password, $encryption]);
            }

            public function grant(string $name, array $grants, bool $replication): void
            {
                $this->driver->recorded('accounts.grant', [$name, $grants, $replication]);
            }

            public function dropUser(string $name): void
            {
                $this->driver->recorded('accounts.dropUser', [$name]);
            }
        };
    }

    public function archive(): Archive
    {
        return new readonly class($this) implements Archive
        {
            public function __construct(private RecordingDriver $driver) {}

            public function status(bool $backUpNow): ArchiveStatus
            {
                return $this->driver->recorded('archive.status', [$backUpNow], new ArchiveStatus(0, null, null, null, null));
            }

            public function restore(string $key, Storage $storage, CarbonImmutable $target): Operation
            {
                return $this->driver->recorded('archive.restore', [$key, $storage, $target], RecordingDriver::running($key));
            }
        };
    }

    public function migration(): Migration
    {
        return new readonly class($this) implements Migration
        {
            public function __construct(private RecordingDriver $driver) {}

            public function check(MigrationSource $source, array $databases): SourceReport
            {
                return $this->driver->recorded('migration.check', [$source, $databases], new SourceReport(true, facts: ['version' => '8.4.3', 'size_mb' => '12'], databases: ['shop']));
            }

            public function start(MigrationStart $start): void
            {
                $this->driver->recorded('migration.start', [$start]);
            }

            public function progress(): MigrationProgress
            {
                return $this->driver->recorded('migration.progress', [], MigrationProgress::working('copying shop'));
            }

            public function stop(): void
            {
                $this->driver->recorded('migration.stop', []);
            }
        };
    }

    public function pools(): Pools
    {
        return new readonly class($this) implements Pools
        {
            public function __construct(private RecordingDriver $driver) {}

            public function list(): array
            {
                return $this->driver->recorded('pools.list', [], []);
            }

            public function create(Pool $pool): void
            {
                $this->driver->recorded('pools.create', [$pool]);
            }

            public function update(Pool $pool): void
            {
                $this->driver->recorded('pools.update', [$pool]);
            }

            public function delete(string $name): void
            {
                $this->driver->recorded('pools.delete', [$name]);
            }
        };
    }

    /**
     * @param  list<mixed>  $args
     */
    public function recorded(string $call, array $args, mixed $default = null): mixed
    {
        return resolve(DriverRecorder::class)->handle($this->node, $call, $args, $default);
    }
}
