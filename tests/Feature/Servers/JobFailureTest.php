<?php

declare(strict_types=1);

use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Networks\JoinWireguardNetworkJob;
use App\Jobs\Networks\LeaveWireguardNetworkJob;
use App\Jobs\Servers\CreateServerUserJob;
use App\Jobs\Servers\RebootServerJob;
use App\Jobs\Servers\RefreshServerFactsJob;
use App\Jobs\Servers\UpdateServerPackagesJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\ServerLog;

function runningLog(Server $server, string $name): ServerLog
{
    return ServerLog::factory()->for($server)->create(['name' => $name, 'status' => ServerLogStatus::Running]);
}

it('fails only the failing job\'s logs', function () {
    $server = Server::factory()->ready()->create();
    $own = runningLog($server, 'Create user app');
    $other = runningLog($server, 'Update packages');

    (new CreateServerUserJob($server, 'app', null))->failed(new RuntimeException('boom'));

    expect($own->fresh()->status)->toBe(ServerLogStatus::Failed)
        ->and($other->fresh()->status)->toBe(ServerLogStatus::Running);
});

it('fails more than one chunk of running logs', function () {
    $server = Server::factory()->ready()->create();
    ServerLog::factory()->for($server)->count(1001)->create(['name' => 'Update packages', 'status' => ServerLogStatus::Running]);

    (new UpdateServerPackagesJob($server))->failed(new RuntimeException('boom'));

    expect(ServerLog::query()->running()->count())->toBe(0)
        ->and(ServerLog::query()->where('status', ServerLogStatus::Failed)->count())->toBe(1001);
});

it('fails the named log of each server job', function (Closure $job, string $name) {
    $server = Server::factory()->ready()->create();
    $log = runningLog($server, $name);

    $job($server)->failed(new RuntimeException('boom'));

    expect($log->fresh()->status)->toBe(ServerLogStatus::Failed);
})->with([
    'refresh facts' => [fn (Server $server) => new RefreshServerFactsJob($server), 'Read server facts'],
    'reboot' => [fn (Server $server) => new RebootServerJob($server), 'Reboot'],
    'leave network' => [fn (Server $server) => new LeaveWireguardNetworkJob($server, Network::factory()->wireguard()->create()), 'Leave Databasify network'],
    'join network' => [fn (Server $server) => new JoinWireguardNetworkJob($server, Network::factory()->wireguard()->create()), 'Join Databasify network'],
]);
