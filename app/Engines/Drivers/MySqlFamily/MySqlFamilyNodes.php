<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\DTOs\Databases\NodeStatus;
use App\Engines\Contracts\Nodes;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Grant;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\NodeMetrics;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;
use App\Engines\Drivers\MariaDb\MariaDbFiles;
use App\Engines\Drivers\Support\NodeState;
use App\Engines\Drivers\TlsPair;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class MySqlFamilyNodes implements Nodes
{
    private const int LIFECYCLE_TIMEOUT = 300;

    private const int WAKE_TIMEOUT = 1900;

    public function __construct(
        private MySqlFamilyDriver $driver,
        private FamilyScripts $scripts,
        private MySqlFamilyAccounts $accounts,
        private NodeState $state,
        private MySqlFamilyConfiguration $configuration,
    ) {}

    public function configure(NodeConfig $config): void
    {
        $this->configuration->configure($config);
    }

    public function start(): void
    {
        $this->scripts->run($this->driver, 'engines.{engine}.nodes.start', timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function wake(bool $all): void
    {
        $this->scripts->run($this->driver, 'engines.{engine}.nodes.wake', timeout: self::WAKE_TIMEOUT);
    }

    public function restart(): void
    {
        $this->scripts->run($this->driver, 'engines.mysql-family.nodes.restart', timeout: self::LIFECYCLE_TIMEOUT);
    }

    public function reset(int $peers, array $hosts): void
    {
        throw EngineUnsupported::command('node reset');
    }

    public function bootstrap(Secrets $secrets, ?DefaultAccount $account): void
    {
        if ($secrets->replication === null || $secrets->agent === null) {
            throw new InvalidEngineRequest('invalid_request', $this->driver->engine()->label.' needs the replication and agent secrets');
        }

        $seed = $this->scripts->read($this->driver, 'engines.{engine}.nodes.bootstrap', ['REPLICATION_PASSWORD' => $secrets->replication, 'AGENT_PASSWORD' => $secrets->agent])->bool('seed');

        if ($seed && $account !== null) {
            $this->accounts->createDatabase($account->database);
            $this->accounts->createUser($account->user, $account->password, [new Grant($account->database, GrantAccess::Write)], null);
        }
    }

    public function installTls(string $certificate, string $key, string $ca): void
    {
        TlsPair::checkPair($certificate, $key, $ca);
        $this->scripts->run($this->driver, 'engines.mysql-family.nodes.tls-install', ['CERTIFICATE' => $certificate, 'KEY' => $key, 'CA' => $ca]);
    }

    public function ready(string $key, bool $expectPrimary): Operation
    {
        return $this->scripts->start($this->driver, $key, 'engines.{engine}.nodes.ready', ['MANIFEST' => $this->manifestWithoutNewCluster()]);
    }

    public function status(): NodeStatus
    {
        $reply = $this->scripts->read($this->driver, 'engines.{engine}.nodes.status');

        return NodeStatus::reported(NodeRole::from($reply->string('role')), $reply->nullableString('version'));
    }

    public function metrics(): NodeMetrics
    {
        return NodeMetrics::fromPayload($this->scripts->read($this->driver, 'engines.mysql-family.nodes.metrics'));
    }

    private function manifestWithoutNewCluster(): string
    {
        if ($this->driver->engine()->id !== 'mariadb') {
            return '';
        }

        $saved = $this->state->requestOf($this->driver, MySqlFamilyConfiguration::REQUEST_STATE);

        return $saved === null ? '' : base64_encode(MariaDbFiles::of($saved, $this->driver->node->database->version_line, false)->manifest);
    }
}
