<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\DTOs\Databases\NodeStatus;
use App\Engines\Contracts\Nodes;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\NodeMetrics;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;
use App\Engines\Drivers\TlsPair;
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
        $this->driver->run('engines.postgres.nodes.start', $this->driver->data(), timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function restart(): void
    {
        $this->driver->run('engines.postgres.nodes.restart', $this->driver->data(), timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function wake(bool $all): void
    {
        $this->driver->run('engines.postgres.nodes.wake', $this->driver->data(), timeout: self::LIFECYCLE_TIMEOUT, env: ['ALL' => $all ? 'true' : 'false']);
    }

    public function reset(int $peers, array $hosts): void
    {
        throw EngineUnsupported::command('node reset');
    }

    public function bootstrap(Secrets $secrets, ?DefaultAccount $account): void
    {
        $passwords = PostgresFiles::passwordsOf($secrets);

        $this->driver->run('engines.postgres.nodes.bootstrap', $this->driver->data(), env: [
            'ADMIN_PASSWORD' => $passwords['admin'],
            'REPLICATION_PASSWORD' => $passwords['replication'],
            'POOLER_PASSWORD' => $passwords['pooler'],
            'ACCOUNT_DATABASE' => $account->database ?? '',
            'ACCOUNT_USER' => $account->user ?? '',
            'ACCOUNT_PASSWORD' => $account->password ?? '',
            'RESERVED_DATABASES' => json_encode(PostgresAccounts::RESERVED_DATABASES, JSON_THROW_ON_ERROR),
        ]);
    }

    public function installTls(string $certificate, string $key, string $ca): void
    {
        TlsPair::checkPair($certificate, $key, $ca);
        $this->driver->run('engines.postgres.nodes.tls-install', $this->driver->data(), env: ['CERTIFICATE' => $certificate, 'KEY' => $key, 'CA' => $ca]);
    }

    public function ready(string $key, bool $expectPrimary): Operation
    {
        return $this->driver->task($key, 'engines.postgres.nodes.ready', $this->driver->data(), env: ['EXPECT_PRIMARY' => $expectPrimary ? 'true' : 'false']);
    }

    public function status(): NodeStatus
    {
        $reply = $this->driver->read('engines.postgres.nodes.status', $this->driver->data());

        return NodeStatus::reported(NodeRole::from($reply->string('role')), $reply->nullableString('version'));
    }

    public function metrics(): NodeMetrics
    {
        return NodeMetrics::fromPayload($this->driver->read('engines.postgres.nodes.metrics', $this->driver->data()));
    }
}
