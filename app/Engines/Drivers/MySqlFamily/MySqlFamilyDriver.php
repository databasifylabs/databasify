<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\EngineFacts;
use App\Engines\Data\HostProfile;
use App\Engines\Data\Ports;
use App\Engines\Drivers\AbstractEngineDriver;
use App\Engines\Drivers\Support\NodeState;
use App\Enums\Engines\Feature;
use App\Support\Databases\ClientPorts;

abstract readonly class MySqlFamilyDriver extends AbstractEngineDriver
{
    abstract public static function engine(): MySqlFamilyEngine;

    public static function facts(string $line): EngineFacts
    {
        $engine = static::engine();

        return new EngineFacts(
            features: [Feature::Accounts, Feature::Archive, Feature::Migration],
            passwordEncryptions: $engine->encryptionsOn($line),
            defaultPasswordEncryption: $engine->defaultEncryption,
            reservedUsers: $engine->reservedUsers,
            reservedDatabases: $engine->reservedDatabases,
            ports: new Ports(ClientPorts::CLIENT, null, $engine->peerPorts),
            hostProfile: new HostProfile(['fs.aio-max-nr' => '1048576', 'net.core.somaxconn' => '4096', 'net.ipv4.tcp_keepalive_intvl' => '10', 'net.ipv4.tcp_keepalive_probes' => '6', 'net.ipv4.tcp_keepalive_time' => '60', 'vm.swappiness' => '1']),
        );
    }

    public function nodes(): MySqlFamilyNodes
    {
        return new MySqlFamilyNodes($this, new FamilyScripts, $this->accounts(), new NodeState, $this->configuration());
    }

    public function cluster(): MySqlFamilyCluster
    {
        return new MySqlFamilyCluster($this, new FamilyScripts, $this->configuration());
    }

    public function config(): MySqlFamilyConfig
    {
        return new MySqlFamilyConfig($this, new FamilyScripts, $this->configuration(), new NodeState);
    }

    public function accounts(): MySqlFamilyAccounts
    {
        return new MySqlFamilyAccounts($this, new FamilyScripts);
    }

    public function archive(): MySqlFamilyArchive
    {
        return new MySqlFamilyArchive($this, new FamilyScripts, $this->configuration());
    }

    public function migration(): MySqlFamilyMigration
    {
        return new MySqlFamilyMigration($this, new FamilyScripts);
    }

    private function configuration(): MySqlFamilyConfiguration
    {
        return new MySqlFamilyConfiguration($this, new FamilyScripts, new NodeState);
    }
}
