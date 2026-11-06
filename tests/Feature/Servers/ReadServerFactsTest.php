<?php

declare(strict_types=1);

use App\Actions\Servers\ReadServerFacts;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Models\Server;

it('stores the facts and leaves the private address as entered', function () {
    $ssh = fakeSsh()->respond("noise\n".'{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":2,"memory_mb":3925,"disk_gb":58,"subnets":["100.72.3.9/10","10.0.0.5/24","10.0.0.9/24","bogus",7]}'."\n");
    $server = Server::factory()->ready()->create(['private_ip' => '10.0.0.9']);

    $facts = resolve(ReadServerFacts::class)->handle($server);

    expect($facts->os)->toBe('Ubuntu 24.04')
        ->and($server->fresh())
        ->private_ip->toBe('10.0.0.9')
        ->cpus->toBe(2)
        ->memory_mb->toBe(3925)
        ->disk_gb->toBe(58)
        ->interface_subnets->all()->toBe(['100.64.0.0/10', '10.0.0.0/24'])
        ->and($server->serverLogs()->value('name'))->toBe('Read server facts')
        ->and($ssh->scriptAt(0))->toContain('/etc/os-release')->toContain('ip -4 -o addr show')->not->toContain('private_ip');
});

it('falls back to unknown facts when the output is not json', function () {
    fakeSsh()->respond("garbage\n");
    $server = Server::factory()->ready()->create(['private_ip' => null]);

    resolve(ReadServerFacts::class)->handle($server);

    expect($server->fresh())
        ->os->toBe('unknown')
        ->cpus->toBe(0)
        ->private_ip->toBeNull();
});

it('fails when the facts script fails', function () {
    fakeSsh()->respond("boom\n", 1);
    $server = Server::factory()->ready()->create(['os' => 'Ubuntu 22.04']);

    expect(fn () => resolve(ReadServerFacts::class)->handle($server))->toThrow(RemoteScriptFailed::class);
    expect($server->fresh()->os)->toBe('Ubuntu 22.04');
});
