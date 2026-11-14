<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Engines\Data\EngineFacts;
use App\Engines\Data\HostProfile;
use App\Engines\Data\Ports;
use App\Engines\Drivers\AbstractEngineDriver;
use App\Engines\Drivers\Support\NodeState;
use App\Enums\Engines\Feature;
use App\Support\Databases\ClientPorts;

final readonly class MongoDbDriver extends AbstractEngineDriver
{
    public static function facts(string $line): EngineFacts
    {
        return new EngineFacts(
            features: [Feature::Accounts, Feature::Archive, Feature::Migration],
            passwordEncryptions: ['SCRAM-SHA-256', 'SCRAM-SHA-1'],
            defaultPasswordEncryption: 'SCRAM-SHA-256',
            reservedUsers: ['root', 'databasify_pbm', '__system'],
            reservedDatabases: ['admin', 'local', 'config'],
            ports: new Ports(ClientPorts::CLIENT, null, []),
            hostProfile: new HostProfile(['net.core.somaxconn' => '4096', 'net.ipv4.tcp_keepalive_intvl' => '10', 'net.ipv4.tcp_keepalive_probes' => '6', 'net.ipv4.tcp_keepalive_time' => '120', 'vm.swappiness' => '1'], floors: ['vm.max_map_count' => 262144]),
        );
    }

    public function nodes(): MongoDbNodes
    {
        return new MongoDbNodes($this, $this->configuration(), $this->accounts());
    }

    public function cluster(): MongoDbCluster
    {
        return new MongoDbCluster($this, $this->configuration(), $this->nodes());
    }

    public function config(): MongoDbConfig
    {
        return new MongoDbConfig($this, $this->configuration(), new NodeState);
    }

    public function accounts(): MongoDbAccounts
    {
        return new MongoDbAccounts($this);
    }

    public function archive(): MongoDbArchive
    {
        return new MongoDbArchive($this, $this->configuration());
    }

    public function migration(): MongoDbMigration
    {
        return new MongoDbMigration($this);
    }

    private function configuration(): MongoDbConfiguration
    {
        return new MongoDbConfiguration($this, new NodeState);
    }
}
