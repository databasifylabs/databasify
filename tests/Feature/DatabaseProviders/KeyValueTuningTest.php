<?php

declare(strict_types=1);

use App\DatabaseProviders\Drivers\Valkey\Valkey;
use App\DTOs\Databases\DatabaseSettings;
use App\DTOs\Databases\NodeResources;
use App\Enums\Databases\Persistence;
use App\Models\Database;

it('leaves fork headroom when snapshots are on', function () {
    $tuned = (new Valkey(new Database(['engine' => 'valkey', 'settings' => new DatabaseSettings([], persistence: Persistence::Snapshots)])))->tunedSettings(new NodeResources(cpus: 1, memoryMb: 2048, diskGb: 50));

    expect($tuned)->toBe(['maxmemory' => '1433mb', 'io-threads' => '1', 'maxclients' => '65504']);
});

it('uses more memory when persistence is off', function () {
    $tuned = (new Valkey(new Database(['engine' => 'valkey', 'settings' => new DatabaseSettings([], persistence: Persistence::Off)])))->tunedSettings(new NodeResources(cpus: 1, memoryMb: 2048, diskGb: 50));

    expect($tuned)->maxmemory->toBe('1740mb');
});

it('gives a large server one io thread per two cpus', function () {
    $tuned = (new Valkey(new Database(['engine' => 'valkey', 'settings' => new DatabaseSettings([], persistence: Persistence::Snapshots)])))->tunedSettings(new NodeResources(cpus: 16, memoryMb: 65536, diskGb: 480));

    expect($tuned)->maxmemory->toBe('45875mb')->{'io-threads'}->toBe('8');
});
