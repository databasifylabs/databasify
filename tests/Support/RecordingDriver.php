<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Databases\FetchedPackages;
use App\DTOs\Databases\NodeStatus;
use App\DTOs\Databases\PackageCandidate;
use App\DTOs\Databases\PackageFetch;
use App\DTOs\Databases\PackageRepository;
use App\DTOs\Databases\QuorumCount;
use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\DTOs\Recovery\ArchiveStatus;
use App\Engines\Contracts\Accounts;
use App\Engines\Contracts\Archive;
use App\Engines\Contracts\Cluster;
use App\Engines\Contracts\Config;
use App\Engines\Contracts\Extensions;
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
use App\Engines\Drivers\Postgres\PostgresExtensionCatalog;
use App\Engines\EngineCatalog;
use App\Enums\Databases\PasswordEncryption;
use Carbon\CarbonImmutable;

final readonly class RecordingDriver extends AbstractEngineDriver
{
    public static function fake(): EngineRecording
    {
        $recording = new EngineRecording;
        app()->instance(EngineRecording::class, $recording);
        EngineCatalog::all()->pluck('driver')->filter()->each(fn (string $driver) => app()->bind($driver, fn ($app, array $parameters): self => new self(...$parameters)));

        return $recording;
    }

    public static function facts(string $line): EngineFacts
    {
        return PostgresDriver::facts($line);
    }

    public function nodes(): Nodes
    {
        return new class($this->node) extends RecordingGroup implements Nodes
        {
            protected string $group = 'nodes';

            public function configure(NodeConfig $config): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function start(): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function restart(): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function wake(bool $all): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function reset(int $peers, array $hosts): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function bootstrap(Secrets $secrets, ?DefaultAccount $account): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function installTls(string $certificate, string $key, string $ca): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function ready(string $key, bool $expectPrimary): Operation
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function status(): NodeStatus
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function metrics(): NodeMetrics
            {
                return $this->read(__FUNCTION__, func_get_args());
            }
        };
    }

    public function cluster(): Cluster
    {
        return new class($this->node) extends RecordingGroup implements Cluster
        {
            protected string $group = 'cluster';

            public function enable(string $key): Operation
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function disable(): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function failover(string $targetIp): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function forceQuorum(bool $othersDown): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function membership(array $members): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function rejoin(string $key, bool $readOnly): Operation
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function forget(string $ip): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function position(): ?int
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function quorum(): QuorumCount
            {
                return $this->read(__FUNCTION__, func_get_args());
            }
        };
    }

    public function config(): Config
    {
        return new class($this->node) extends RecordingGroup implements Config
        {
            protected string $group = 'config';

            public function apply(): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function sync(): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function pendingRestart(): array
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function fields(): array
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function tune(): array
            {
                return $this->read(__FUNCTION__, func_get_args());
            }
        };
    }

    public function accounts(): Accounts
    {
        return new class($this->node) extends RecordingGroup implements Accounts
        {
            protected string $group = 'accounts';

            public function list(): AccountList
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function createDatabase(string $name): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function dropDatabase(string $name): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function createUser(string $name, string $password, array $grants, ?PasswordEncryption $encryption): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function setPassword(string $name, string $password, ?PasswordEncryption $encryption): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function grant(string $name, array $grants, bool $replication): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function dropUser(string $name): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }
        };
    }

    public function archive(): Archive
    {
        return new class($this->node) extends RecordingGroup implements Archive
        {
            protected string $group = 'archive';

            public function status(bool $backUpNow): ArchiveStatus
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function restore(string $key, Storage $storage, CarbonImmutable $target): Operation
            {
                return $this->read(__FUNCTION__, func_get_args());
            }
        };
    }

    public function migration(): Migration
    {
        return new class($this->node) extends RecordingGroup implements Migration
        {
            protected string $group = 'migration';

            public function check(MigrationSource $source, array $databases): SourceReport
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function start(MigrationStart $start): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function progress(): MigrationProgress
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function stop(string $key, bool $cutover): Operation
            {
                return $this->read(__FUNCTION__, func_get_args());
            }
        };
    }

    public function pools(): Pools
    {
        return new class($this->node) extends RecordingGroup implements Pools
        {
            protected string $group = 'pools';

            public function list(): array
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function create(Pool $pool): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function update(Pool $pool): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function delete(string $name): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }
        };
    }

    public function extensions(): Extensions
    {
        return new class($this->node) extends RecordingGroup implements Extensions
        {
            protected string $group = 'extensions';

            public function available(): array
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function catalog(): array
            {
                return PostgresExtensionCatalog::entries();
            }

            public function officialRepository(): PackageRepository
            {
                return new PackageRepository('PGDG', 'apt.postgresql.org', 'postgresql-18-http');
            }

            public function packageDirectory(): string
            {
                return '/srv/postgres/packages';
            }

            public function list(string $schema): array
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function enable(string $schema, string $name): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function update(string $schema, string $name): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function disable(string $schema, string $name): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function usedIn(string $name): array
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function allowInstalls(): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function architecture(): string
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function operatingSystem(): string
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function fetchPackages(PackageFetch $fetch): FetchedPackages
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function packageCandidate(PackageFetch $fetch): PackageCandidate
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function uploadedPackageNames(string $run): array
            {
                return $this->read(__FUNCTION__, func_get_args());
            }

            public function installPackages(array $fileNames, bool $allowDowngrades = false): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function removePackages(array $packageNames): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function discardFetch(string $run): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }

            public function keepPackageFiles(array $fileNames): void
            {
                $this->record(__FUNCTION__, func_get_args());
            }
        };
    }
}
