<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Data\EngineFacts;
use App\Engines\Data\HostProfile;
use App\Engines\Data\Ports;
use App\Engines\Drivers\AbstractEngineDriver;
use App\Engines\Drivers\Support\NodeState;
use App\Engines\Scripts\ScriptView;
use App\Enums\Engines\Feature;
use App\Support\Databases\ClientPorts;

final readonly class PostgresDriver extends AbstractEngineDriver
{
    public static function facts(string $line): EngineFacts
    {
        return new EngineFacts(
            features: [Feature::Accounts, Feature::Archive, Feature::Migration, Feature::Pools],
            passwordEncryptions: [],
            defaultPasswordEncryption: null,
            reservedUsers: PostgresAccounts::RESERVED_USERS,
            reservedDatabases: PostgresAccounts::RESERVED_DATABASES,
            ports: new Ports(ClientPorts::CLIENT, ClientPorts::POOL, [8008, 2379, 2380]),
            hostProfile: new HostProfile(['net.core.somaxconn' => '4096', 'net.ipv4.tcp_keepalive_intvl' => '10', 'net.ipv4.tcp_keepalive_probes' => '6', 'net.ipv4.tcp_keepalive_time' => '60', 'vm.swappiness' => '1']),
        );
    }

    public function nodes(): PostgresNodes
    {
        return new PostgresNodes($this, $this->configuration());
    }

    public function cluster(): PostgresCluster
    {
        return new PostgresCluster($this, $this->configuration());
    }

    public function config(): PostgresConfig
    {
        return new PostgresConfig($this, new NodeState, $this->configuration());
    }

    public function accounts(): PostgresAccounts
    {
        return new PostgresAccounts($this);
    }

    public function archive(): PostgresArchive
    {
        return new PostgresArchive($this, resolve(PostgresFiles::class));
    }

    public function migration(): PostgresMigration
    {
        return new PostgresMigration($this, resolve(ScriptView::class));
    }

    public function pools(): PostgresPools
    {
        return new PostgresPools($this, $this->accounts());
    }

    /**
     * @return array{port: int}
     */
    public function data(): array
    {
        return ['port' => $this->node->database->clientPort()];
    }

    private function configuration(): PostgresConfiguration
    {
        return new PostgresConfiguration($this, new NodeState, resolve(PostgresFiles::class));
    }
}
