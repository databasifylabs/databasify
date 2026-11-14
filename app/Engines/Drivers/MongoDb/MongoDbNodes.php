<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\DTOs\Databases\NodeStatus;
use App\Engines\Contracts\Nodes;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Grant;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\NodeMetrics;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;
use App\Engines\Drivers\TlsPair;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class MongoDbNodes implements Nodes
{
    private const int LIFECYCLE_TIMEOUT = 300;

    public function __construct(
        private MongoDbDriver $driver,
        private MongoDbConfiguration $configuration,
        private MongoDbAccounts $accounts,
    ) {}

    public function configure(NodeConfig $config): void
    {
        $this->configuration->configure($config);
    }

    public function start(): void
    {
        $this->driver->run('mongodb/nodes/start', timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function wake(bool $all): void
    {
        $this->driver->run('mongodb/nodes/wake', timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function restart(): void
    {
        $this->driver->run('mongodb/nodes/restart', timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function reset(int $peers, array $hosts): void
    {
        throw EngineUnsupported::command('node reset');
    }

    public function bootstrap(Secrets $secrets, ?DefaultAccount $account): void
    {
        if ($secrets->admin === null || $secrets->agent === null) {
            throw new InvalidEngineRequest('invalid_request', 'MongoDB needs the admin and agent secrets');
        }

        $saved = $this->configuration->savedRequestOf();

        if (! $this->isSeed($saved)) {
            return;
        }

        $this->driver->run('mongodb/nodes/bootstrap', timeout: 300, env: [
            'HOST' => MongoDbFiles::hostOf($saved->ip),
            'ADMIN_PASSWORD' => $secrets->admin,
            'AGENT_PASSWORD' => $secrets->agent,
        ]);

        if ($account !== null) {
            $this->accounts->createDatabase($account->database);
            $this->accounts->createUser($account->user, $account->password, [new Grant($account->database, GrantAccess::Write)], null);
        }
    }

    public function installTls(string $certificate, string $key, string $ca): void
    {
        TlsPair::checkPair($certificate, $key, $ca);
        $this->driver->run('mongodb/nodes/tls-install', env: ['PEM' => rtrim($certificate, "\n")."\n".$key, 'CA' => $ca]);
    }

    public function ready(string $key, bool $expectPrimary): Operation
    {
        return $this->driver->task($key, 'mongodb/nodes/ready');
    }

    public function isSeed(NodeConfig $request): bool
    {
        return ! $request->clustered || (! $request->readOnly && ($request->primary === null || $request->primary === $request->ip));
    }

    public function status(): NodeStatus
    {
        $reply = $this->driver->read('mongodb/nodes/status');

        return NodeStatus::reported(NodeRole::from($reply->string('role')), $reply->nullableString('version'));
    }

    public function metrics(): NodeMetrics
    {
        return NodeMetrics::fromPayload($this->driver->read('mongodb/nodes/metrics'));
    }
}
