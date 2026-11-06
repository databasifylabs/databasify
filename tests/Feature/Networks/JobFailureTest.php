<?php

declare(strict_types=1);

use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Networks\SyncWireguardNetworkJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\ServerLog;

it('fails the network update logs of every member and nothing else', function () {
    $network = Network::factory()->wireguard()->create();
    $member = Server::factory()->onNetwork($network)->create(['private_ip' => '100.64.0.1']);
    $outsider = Server::factory()->ready()->create();
    $update = ServerLog::factory()->for($member)->create(['name' => 'Update Databasify network', 'status' => ServerLogStatus::Running]);
    $unrelated = ServerLog::factory()->for($member)->create(['name' => 'Update packages', 'status' => ServerLogStatus::Running]);
    $elsewhere = ServerLog::factory()->for($outsider)->create(['name' => 'Update Databasify network', 'status' => ServerLogStatus::Running]);

    (new SyncWireguardNetworkJob($network))->failed(new RuntimeException('boom'));

    expect($update->fresh()->status)->toBe(ServerLogStatus::Failed)
        ->and($unrelated->fresh()->status)->toBe(ServerLogStatus::Running)
        ->and($elsewhere->fresh()->status)->toBe(ServerLogStatus::Running);
});
