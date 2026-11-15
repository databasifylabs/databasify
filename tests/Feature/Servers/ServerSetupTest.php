<?php

declare(strict_types=1);

use App\Enums\Servers\ServerLogStatus;
use App\Enums\Servers\ServerStatus;
use App\Exceptions\Servers\UnsupportedOperatingSystem;
use App\Jobs\Servers\LaunchProviderServerJob;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Server;
use App\Models\ServerLog;
use App\Models\ServerProvider;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Validation\ValidationException;

function setupFactsOf(string $os, string $id, string $version): string
{
    return json_encode(['os' => $os, 'id' => $id, 'version_id' => $version, 'selinux' => 'Disabled', 'cpus' => 2, 'memory_mb' => 4096, 'disk_gb' => 80, 'subnets' => []])."\n";
}

test('a provisioned server becomes ready and forgets its password', function (bool $disablePasswordLogin) {
    $server = Server::factory()->onNetwork(null)->create(['ssh_password' => 'root-password']);
    $this->ssh()->replyTo('/etc/os-release', setupFactsOf('Ubuntu 24.04 LTS', 'ubuntu', '24.04'));

    dispatch(new ProvisionServerJob($server, $disablePasswordLogin));

    expect($server->refresh())
        ->status->toBe(ServerStatus::Ready)
        ->ssh_password->toBeNull()
        ->and($this->ssh()->ran('PasswordAuthentication no', $server))->toBe($disablePasswordLogin);
})->with([
    'password login turned off' => true,
    'password login kept' => false,
]);

test('a failing setup step marks the server failed', function () {
    $server = Server::factory()->onNetwork(null)->create();
    $this->ssh()->replyTo('/etc/os-release', "permission denied\n", exit: 1);

    expect(fn () => dispatch(new ProvisionServerJob($server, disablePasswordLogin: false)))->toThrow(ValidationException::class);

    expect($server->refresh()->status)->toBe(ServerStatus::Failed)
        ->and($server->serverLogs()->sole())
        ->name->toBe('Read server facts')
        ->status->toBe(ServerLogStatus::Failed);
});

test('a server on an unsupported operating system is marked failed before any setup step', function () {
    $server = Server::factory()->onNetwork(null)->create();
    $this->ssh()->replyTo('/etc/os-release', setupFactsOf('Debian GNU/Linux 11 (bullseye)', 'debian', '11'));

    expect(fn () => dispatch(new ProvisionServerJob($server, disablePasswordLogin: false)))
        ->toThrow(UnsupportedOperatingSystem::class, 'This server runs Debian GNU/Linux 11 (bullseye).');

    expect($server->refresh()->status)->toBe(ServerStatus::Failed)
        ->and($server->serverLogs()->pluck('name')->all())->toBe(['Read server facts']);
});

test('a setup job the queue gives up on marks the server failed', function (ServerStatus $status, string $step, Closure $job) {
    $server = Server::factory()->for(ServerProvider::factory())->create(['status' => $status]);
    $running = ServerLog::factory()->for($server)->create(['name' => $step, 'status' => ServerLogStatus::Running, 'exit_code' => null]);

    $job($server)->failed(new MaxAttemptsExceededException('attempted too many times'));

    expect($server->refresh()->status)->toBe(ServerStatus::Failed)
        ->and($running->refresh()->status)->toBe(ServerLogStatus::Failed);
})->with([
    'queued but never started' => [ServerStatus::Pending, 'Read server facts', fn (Server $server) => new ProvisionServerJob($server, disablePasswordLogin: true)],
    'provisioning' => [ServerStatus::Provisioning, 'Turning off password login', fn (Server $server) => new ProvisionServerJob($server, disablePasswordLogin: true)],
    'launching at the provider' => [ServerStatus::Creating, 'Wait for SSH', fn (Server $server) => new LaunchProviderServerJob($server)],
]);

test('a failed setup job leaves the logs of other operations running', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning]);
    $other = ServerLog::factory()->for($server)->create(['name' => 'Update packages', 'status' => ServerLogStatus::Running, 'exit_code' => null]);

    (new ProvisionServerJob($server, disablePasswordLogin: true))->failed(new MaxAttemptsExceededException('attempted too many times'));

    expect($other->refresh()->status)->toBe(ServerLogStatus::Running);
});

test('a late failure leaves a ready server ready', function () {
    $server = Server::factory()->ready()->create();

    (new ProvisionServerJob($server, disablePasswordLogin: true))->failed(new MaxAttemptsExceededException('attempted too many times'));

    expect($server->refresh()->status)->toBe(ServerStatus::Ready);
});
