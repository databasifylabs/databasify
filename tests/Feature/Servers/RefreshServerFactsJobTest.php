<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Jobs\Servers\RefreshServerFactsJob;
use App\Models\Server;

it('reads the latest facts of the server', function () {
    fakeSsh()->respond('{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":8,"memory_mb":16000,"disk_gb":160,"private_ip":"10.0.0.2"}'."\n");
    $server = Server::factory()->ready()->create(['cpus' => 2]);

    dispatch(new RefreshServerFactsJob($server));

    expect($server->fresh())
        ->cpus->toBe(8)
        ->disk_gb->toBe(160);
});

it('asks every database on a resized server to retune', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $other = idleDatabase('valkey', 1, DatabaseMode::Single, 'other');
    $server = $database->seed()->server;
    $server->update(['cpus' => 2, 'memory_mb' => 4096]);
    $other->seed()->server->update(['cpus' => 2, 'memory_mb' => 4096]);
    fakeSsh()->respond('{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":4,"memory_mb":8192,"disk_gb":160}'."\n");

    dispatch(new RefreshServerFactsJob($server->fresh()));

    expect($database->fresh()->retune_needed_at)->not->toBeNull()
        ->and($other->fresh()->retune_needed_at)->toBeNull();
});

it('leaves databases alone when the server keeps its size', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $server = $database->seed()->server;
    $server->update(['cpus' => 4, 'memory_mb' => 8192, 'disk_gb' => 80]);
    fakeSsh()->respond('{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":4,"memory_mb":8192,"disk_gb":160}'."\n");

    dispatch(new RefreshServerFactsJob($server->fresh()));

    expect($database->fresh()->retune_needed_at)->toBeNull();
});
