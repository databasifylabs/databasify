<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Data\EngineFacts;
use App\Engines\Data\HostProfile;
use App\Engines\Data\Ports;
use App\Engines\Drivers\AbstractEngineDriver;
use App\Engines\Drivers\Support\NodeState;
use App\Enums\Engines\Feature;
use App\Support\Databases\ClientPorts;

abstract readonly class SentinelDriver extends AbstractEngineDriver
{
    abstract public static function flavor(): SentinelFamilyEngine;

    public static function facts(string $line): EngineFacts
    {
        return new EngineFacts(
            features: [Feature::Migration],
            passwordEncryptions: [],
            defaultPasswordEncryption: null,
            reservedUsers: [],
            reservedDatabases: [],
            ports: new Ports(ClientPorts::KEY_VALUE, null, [SentinelFiles::DATA_PORT, SentinelFiles::SENTINEL_PORT]),
            hostProfile: new HostProfile(['vm.overcommit_memory' => '1'], floors: ['net.core.somaxconn' => 1024], disableTransparentHugepages: true),
        );
    }

    public function nodes(): SentinelNodes
    {
        return new SentinelNodes($this, new SentinelScripts, $this->configuration());
    }

    public function cluster(): SentinelCluster
    {
        return new SentinelCluster($this, new SentinelScripts, $this->configuration());
    }

    public function config(): SentinelConfig
    {
        return new SentinelConfig($this, new SentinelScripts, $this->configuration(), new NodeState);
    }

    public function migration(): SentinelMigration
    {
        return new SentinelMigration($this, new SentinelScripts, $this->configuration());
    }

    private function configuration(): SentinelConfiguration
    {
        return new SentinelConfiguration($this, new SentinelScripts, new NodeState);
    }
}
