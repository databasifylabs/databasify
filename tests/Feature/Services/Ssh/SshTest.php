<?php

declare(strict_types=1);

use App\Enums\Servers\ServerLogStatus;
use App\Events\ServerLogOutputReceived;
use App\Exceptions\Databases\OperationStopped;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\Server;
use App\Models\ServerLog;
use App\Services\Ssh\AppSshKey;
use App\Services\Ssh\Ssh;
use App\Support\Ssh\Fingerprint;
use Illuminate\Support\Facades\Event;

it('records a named script in its own server log', function () {
    $ssh = fakeSsh()->respond("hello\n");
    $server = Server::factory()->ready()->create();
    Event::fake([ServerLogOutputReceived::class]);

    $output = $server->ssh()->exec('ssh.servers.provision.facts', [], 'Read facts');

    expect($output)->toBe("hello\n")
        ->and($server->serverLogs()->sole())
        ->name->toBe('Read facts')
        ->status->toBe(ServerLogStatus::Succeeded)
        ->output->toBe("hello\n")
        ->exit_code->toBe(0)
        ->and($ssh->runs[0]['timeout'])->toBe(Ssh::TIMEOUT)
        ->and($ssh->scriptAt(0))->toStartWith("set -euo pipefail\nexport DEBIAN_FRONTEND=noninteractive\n");
    Event::assertDispatched(ServerLogOutputReceived::class, fn (ServerLogOutputReceived $event): bool => $event->chunk === "hello\n");
});

it('tells listeners where each chunk starts in the output', function () {
    fakeSsh()->respond("abc\n");
    $log = ServerLog::factory()->create(['status' => ServerLogStatus::Running, 'output' => 'saved']);
    Event::fake([ServerLogOutputReceived::class]);

    $log->write('one');
    $log->write('two');
    $log->finish(0);

    expect(Event::dispatched(ServerLogOutputReceived::class)->map(fn (array $event): array => [$event[0]->chunk, $event[0]->offset])->all())
        ->toBe([['one', 5], ['two', 8], ['', 11]])
        ->and($log->fresh()->output)->toBe('savedonetwo');
});

it('broadcasts a freshly started log as running', function () {
    $log = Server::factory()->ready()->create()->startLog('Wait for SSH');
    Event::fake([ServerLogOutputReceived::class]);

    $log->write('waiting');

    Event::assertDispatched(ServerLogOutputReceived::class, fn (ServerLogOutputReceived $event): bool => $event->broadcastWith()['status'] === 'running');
});

it('gives every named call its own log', function () {
    fakeSsh();
    $server = Server::factory()->ready()->create();

    $server->ssh()->exec('ssh.databases.noop', [], 'First');
    $server->ssh()->exec('ssh.servers.provision.facts', [], 'Second', timeout: 7200);

    expect($server->serverLogs()->pluck('name')->sort()->values()->all())->toBe(['First', 'Second']);
});

it('links the log to the database it ran for', function () {
    fakeSsh();
    $database = idleDatabase('postgres', 1);
    $node = $database->nodes()->with('server')->sole();

    $node->ssh()->exec('ssh.databases.noop', [], 'Starting');

    expect(ServerLog::query()->sole())->server_id->toBe($node->server_id)->database_id->toBe($database->id);
});

it('fails the log and names it when the script exits non-zero', function () {
    fakeSsh()->respond("boom\n", 7);
    $server = Server::factory()->ready()->create(['name' => 'db-1']);

    expect(fn () => $server->ssh()->exec('ssh.databases.noop', [], 'Bootstrap'))
        ->toThrow(RemoteScriptFailed::class, 'Bootstrap on db-1 failed with exit code 7.')
        ->and($server->serverLogs()->sole())->status->toBe(ServerLogStatus::Failed)->exit_code->toBe(7)->output->toBe("boom\n");
});

it('writes connection errors into the log', function () {
    $server = Server::factory()->ready()->create();
    fakeSsh()->failWith(new SshConnectionFailed('unreachable'));

    expect(fn () => $server->ssh()->exec('ssh.databases.noop', [], 'Probe'))->toThrow(SshConnectionFailed::class)
        ->and($server->serverLogs()->sole())->status->toBe(ServerLogStatus::Failed)->output->toBe("▸ unreachable\n");
});

it('runs probes without a log and names the view when they fail', function () {
    $ssh = fakeSsh()->respond("role:master\n")->respond('', 2);
    $server = Server::factory()->ready()->create(['name' => 'cache-1']);

    expect($server->ssh()->exec('ssh.databases.noop'))->toBe("role:master\n")
        ->and($ssh->runs[0])->toMatchArray(['server' => 'cache-1', 'timeout' => Ssh::PROBE_TIMEOUT])
        ->and(fn () => $server->ssh()->exec('ssh.databases.noop', timeout: 5))->toThrow(RemoteScriptFailed::class, 'ssh.databases.noop on cache-1 failed with exit code 2.')
        ->and($ssh->runs[1]['timeout'])->toBe(5)
        ->and($server->serverLogs()->exists())->toBeFalse();
});

it('records a secret call without its output', function () {
    fakeSsh()->respond("CERT server.pem cGVt\n");
    $server = Server::factory()->ready()->create();

    $output = $server->ssh()->exec('ssh.databases.noop', [], 'Issuing certificates', secret: true);

    expect($output)->toBe("CERT server.pem cGVt\n")
        ->and($server->serverLogs()->sole())->name->toBe('Issuing certificates')->status->toBe(ServerLogStatus::Succeeded)->output->toBeNull();
});

it('refuses the next step of a database once it is asked to stop', function () {
    $ssh = fakeSsh();
    $database = idleDatabase('postgres', 1);
    $node = $database->nodes()->with('server')->sole();
    $database->startOperation('Reboot servers');

    $database->requestStop();

    expect(fn () => $node->ssh()->exec('ssh.databases.noop', [], 'db-1: rebooting'))
        ->toThrow(OperationStopped::class)
        ->and($ssh->runs)->toBeEmpty()
        ->and($database->serverLogs()->sole())->name->toBe('db-1: rebooting')->status->toBe(ServerLogStatus::Failed)->output->toBe("▸ Stopped by you before this ran.\n");
});

it('generates the app key pair once', function () {
    $first = resolve(AppSshKey::class)->get();
    $second = resolve(AppSshKey::class)->get();

    expect($second->id)->toBe($first->id)
        ->and($first->public_key)->toStartWith('ssh-ed25519 ')
        ->and($first->private_key)->toContain('OPENSSH PRIVATE KEY');
});

it('fingerprints an OpenSSH host key like ssh-keygen', function () {
    $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

    expect(Fingerprint::sha256($key))->toBe('SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU');
});

it('makes every apt-get wait for the package lock instead of failing', function () {
    $ssh = fakeSsh();
    $server = Server::factory()->ready()->create();

    $server->ssh()->exec('ssh.servers.os.update-packages');

    expect($ssh->scriptAt(0))
        ->toContain('fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock')->not->toContain('unattended-upgr')
        ->toContain('apt-get() { apt_wait; command apt-get -o DPkg::Lock::Timeout=600 "$@"; }')
        ->toContain('timeout 600 cloud-init status --wait')
        ->and(strpos($ssh->scriptAt(0), 'cloud-init status --wait'))->toBeLessThan(strpos($ssh->scriptAt(0), 'apt-get update -q'));
});
