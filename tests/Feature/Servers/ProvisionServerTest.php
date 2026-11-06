<?php

declare(strict_types=1);

use App\Actions\Servers\AddServer;
use App\Actions\Servers\ProvisionServer;
use App\DTOs\Servers\NewServer;
use App\Enums\Servers\OperatingSystem;
use App\Enums\Servers\ServerLogStatus;
use App\Enums\Servers\ServerStatus;
use App\Exceptions\Servers\UnsupportedOperatingSystem;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Server;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function osRelease(string $system): string
{
    return [
        'ubuntu-24.04' => "PRETTY_NAME=\"Ubuntu 24.04.3 LTS\"\nNAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\nVERSION=\"24.04.3 LTS (Noble Numbat)\"\nVERSION_CODENAME=noble\nID=ubuntu\nID_LIKE=debian\nUBUNTU_CODENAME=noble\n",
        'ubuntu-26.04' => "PRETTY_NAME=\"Ubuntu 26.04 LTS\"\nNAME=\"Ubuntu\"\nVERSION_ID=\"26.04\"\nVERSION=\"26.04 LTS (Resolute Raccoon)\"\nVERSION_CODENAME=resolute\nID=ubuntu\nID_LIKE=debian\nUBUNTU_CODENAME=resolute\n",
        'ubuntu-22.04' => "PRETTY_NAME=\"Ubuntu 22.04.5 LTS\"\nNAME=\"Ubuntu\"\nVERSION_ID=\"22.04\"\nVERSION=\"22.04.5 LTS (Jammy Jellyfish)\"\nVERSION_CODENAME=jammy\nID=ubuntu\nID_LIKE=debian\n",
        'debian-12' => "PRETTY_NAME=\"Debian GNU/Linux 12 (bookworm)\"\nNAME=\"Debian GNU/Linux\"\nVERSION_ID=\"12\"\nVERSION=\"12 (bookworm)\"\nVERSION_CODENAME=bookworm\nID=debian\n",
        'debian-13' => "PRETTY_NAME=\"Debian GNU/Linux 13 (trixie)\"\nNAME=\"Debian GNU/Linux\"\nVERSION_ID=\"13\"\nVERSION=\"13 (trixie)\"\nVERSION_CODENAME=trixie\nDEBIAN_VERSION_FULL=13.1\nID=debian\n",
        'rocky-9' => "NAME=\"Rocky Linux\"\nVERSION=\"9.6 (Blue Onyx)\"\nID=\"rocky\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"9.6\"\nPLATFORM_ID=\"platform:el9\"\nPRETTY_NAME=\"Rocky Linux 9.6 (Blue Onyx)\"\nCPE_NAME=\"cpe:/o:rocky:rocky:9::baseos\"\n",
        'almalinux-9' => "NAME=\"AlmaLinux\"\nVERSION=\"9.6 (Sage Margay)\"\nID=\"almalinux\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"9.6\"\nPLATFORM_ID=\"platform:el9\"\nPRETTY_NAME=\"AlmaLinux 9.6 (Sage Margay)\"\n",
        'centos-7' => "NAME=\"CentOS Linux\"\nVERSION=\"7 (Core)\"\nID=\"centos\"\nID_LIKE=\"rhel fedora\"\nVERSION_ID=\"7\"\nPRETTY_NAME=\"CentOS Linux 7 (Core)\"\n",
        'centos-9' => "NAME=\"CentOS Stream\"\nVERSION=\"9\"\nID=\"centos\"\nID_LIKE=\"rhel fedora\"\nVERSION_ID=\"9\"\nPLATFORM_ID=\"platform:el9\"\nPRETTY_NAME=\"CentOS Stream 9\"\n",
    ][$system];
}

it('stores the server and queues provisioning', function () {
    Queue::fake();

    $server = resolve(AddServer::class)->handle(new NewServer('db-1', '203.0.113.10', 22, 'root', 'secret', true));

    expect($server->status)->toBe(ServerStatus::Pending)
        ->and($server->ssh_password)->toBe('secret');
    Queue::assertPushed(ProvisionServerJob::class, fn (ProvisionServerJob $job) => $job->disablePasswordLogin && $job->server->is($server));
});

it('provisions, reads facts and forgets the password', function () {
    $ssh = fakeSsh()
        ->respond('{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":2,"memory_mb":3925,"disk_gb":58}'."\n")
        ->respond()
        ->respond("app key installed\n");
    $server = Server::factory()->create(['ssh_password' => 'secret']);

    resolve(ProvisionServer::class)->handle($server, true);

    expect($server->fresh())
        ->status->toBe(ServerStatus::Ready)
        ->ssh_password->toBeNull()
        ->private_ip->toBe($server->private_ip)
        ->os->toBe('Ubuntu 24.04')
        ->cpus->toBe(2)
        ->memory_mb->toBe(3925);
    expect($server->serverLogs()->pluck('name')->all())->toBe([
        'Read server facts',
        'Checking the system',
        'Installing the Databasify key',
        'Updating packages',
        'Installing base tools',
        'Installing Docker',
        'Adding swap',
        'Turning off password login',
    ])
        ->and($server->serverLogs()->pluck('status')->unique()->all())->toBe([ServerLogStatus::Succeeded])
        ->and($server->serverLogs()->where('name', 'Installing the Databasify key')->value('output'))->toBe("app key installed\n");
    expect($ssh->scriptAt(0))->toContain('/etc/os-release')
        ->and($ssh->scriptAt(2))->toContain('authorized_keys')
        ->and($ssh->scriptAt(4))->toContain('apt-get install -yq ca-certificates curl gnupg jq openssl')
        ->and($ssh->scriptAt(6))->toContain('fallocate -l 2G /swapfile')
        ->and($ssh->scriptAt(7))
        ->toContain('PasswordAuthentication no')
        ->toContain("install -d -m 755 /run/sshd\nsshd -t");
});

it('checks the private address before setting the server up', function () {
    $ssh = fakeSsh()->respond('{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":1,"memory_mb":1024,"disk_gb":25}'."\n")->respond("10.0.1.2 is not assigned on this server.\n", 1);
    $server = Server::factory()->create(['private_ip' => '10.0.1.2']);

    expect(fn () => resolve(ProvisionServer::class)->handle($server, false))
        ->toThrow(RemoteScriptFailed::class);
    expect($ssh->scriptAt(1))->toContain("grep -qxF '10.0.1.2'")
        ->and($ssh->runs)->toHaveCount(2)
        ->and($server->fresh()->status)->toBe(ServerStatus::Failed);
});

it('refuses to set up an unsupported system before running anything on it', function () {
    $ssh = fakeSsh()->respond('{"os":"CentOS Linux 7 (Core)","id":"centos","version_id":"7","cpus":1,"memory_mb":1024,"disk_gb":25}'."\n");
    $server = Server::factory()->create();

    expect(fn () => resolve(ProvisionServer::class)->handle($server, false))
        ->toThrow(UnsupportedOperatingSystem::class, 'This server runs CentOS Linux 7 (Core).');
    expect($ssh->runs)->toHaveCount(1)
        ->and($server->fresh()->status)->toBe(ServerStatus::Failed);
});

it('accepts only the supported releases', function (string $osRelease, bool $supported) {
    $release = parse_ini_string($osRelease);

    expect(OperatingSystem::fromRelease($release['ID'], $release['VERSION_ID']) !== null)->toBe($supported);
})->with([
    'ubuntu 24.04' => [osRelease('ubuntu-24.04'), true],
    'ubuntu 26.04' => [osRelease('ubuntu-26.04'), true],
    'debian 12' => [osRelease('debian-12'), true],
    'debian 13' => [osRelease('debian-13'), true],
    'rocky 9' => [osRelease('rocky-9'), true],
    'almalinux 9' => [osRelease('almalinux-9'), true],
    'rocky 9 without a point release' => ["NAME=\"Rocky Linux\"\nID=\"rocky\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"9\"\n", true],
    'ubuntu 22.04' => [osRelease('ubuntu-22.04'), false],
    'ubuntu 25.10' => ["ID=ubuntu\nVERSION_ID=\"25.10\"\n", false],
    'debian 11' => ["ID=debian\nVERSION_ID=\"11\"\n", false],
    'centos 7' => [osRelease('centos-7'), false],
    'centos stream 9' => [osRelease('centos-9'), false],
    'rocky 10' => ["ID=\"rocky\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"10.0\"\n", false],
    'almalinux 8' => ["ID=\"almalinux\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"8.10\"\n", false],
    'rocky 90' => ["ID=\"rocky\"\nVERSION_ID=\"90.1\"\n", false],
]);

it('installs docker from the rhel repository with the same pins, locks and daemon settings', function (string $facts) {
    config(['databasify.docker' => ['version' => '29.9.2', 'containerd' => '2.4.2']]);
    $ssh = fakeSsh()->respond($facts."\n");
    $server = Server::factory()->create();

    resolve(ProvisionServer::class)->handle($server, true);

    $docker = $ssh->scriptAt(5);
    $daemon = json_decode(Str::between($docker, "docker_settings=\$(cat <<'JSON'\n", "\nJSON\n"), true);
    expect($daemon)->toMatchArray(['live-restore' => true, 'iptables' => false, 'ip6tables' => false, 'bridge' => 'none'])
        ->and($ssh->scriptAt(4))
        ->toContain('dnf install -y -q ca-certificates jq openssl nftables wireguard-tools dnf-plugins-core python3-dnf-plugin-versionlock')
        ->not->toContain('apt-get install -yq ca-certificates')
        ->and($docker)
        ->toContain('https://download.docker.com/linux/centos/docker-ce.repo')
        ->toContain("engine=\$(docker_build docker-ce '29.9.2-')")
        ->toContain("cli=\$(docker_build docker-ce-cli '29.9.2-')")
        ->toContain("containerd=\$(docker_build containerd.io '2.4.2-')")
        ->toContain('"docker-ce-$engine" "docker-ce-cli-$cli" "containerd.io-$containerd"')
        ->toContain('dnf versionlock add docker-ce docker-ce-cli containerd.io')
        ->toContain("if [ \"\$(docker info --format '{{.LiveRestoreEnabled}}')\" != true ]; then")
        ->not->toContain('apt-mark')
        ->and($ssh->scriptAt(6))->toContain('selinux_label swapfile_t /swapfile')
        ->and($ssh->scriptAt(7))->toContain('PasswordAuthentication no')
        ->and(strpos($docker, '/etc/docker/daemon.json'))->toBeLessThan(strpos($docker, 'dnf install -y -q --allowerasing'))
        ->and(strpos($docker, 'dnf versionlock delete'))->toBeLessThan(strpos($docker, 'dnf install -y -q --allowerasing'))
        ->and(collect($ssh->runs)->map(fn (array $run) => bashErrorsIn($run['script']))->filter()->all())->toBe([]);
})->with([
    'rocky 9' => ['{"os":"Rocky Linux 9.6 (Blue Onyx)","id":"rocky","version_id":"9.6","selinux":"Enforcing","cpus":2,"memory_mb":4000,"disk_gb":80}'],
    'almalinux 9' => ['{"os":"AlmaLinux 9.6 (Sage Margay)","id":"almalinux","version_id":"9.6","selinux":"Enforcing","cpus":2,"memory_mb":4000,"disk_gb":80}'],
]);

it('provisions the debian family with apt for every debian and ubuntu release', function (string $id, string $versionId) {
    $ssh = fakeSsh()->respond(json_encode(['os' => "{$id} {$versionId}", 'id' => $id, 'version_id' => $versionId, 'cpus' => 2, 'memory_mb' => 4000, 'disk_gb' => 80])."\n");
    $server = Server::factory()->create();

    resolve(ProvisionServer::class)->handle($server, false);

    expect($ssh->scriptAt(4))->toContain('apt-get install -yq ca-certificates curl gnupg jq openssl')
        ->and($ssh->scriptAt(5))
        ->toContain('apt-mark hold docker-ce docker-ce-cli containerd.io')
        ->not->toContain('dnf versionlock')
        ->not->toContain('docker-ce.repo');
})->with([
    'ubuntu 24.04' => ['ubuntu', '24.04'],
    'ubuntu 26.04' => ['ubuntu', '26.04'],
    'debian 12' => ['debian', '12'],
    'debian 13' => ['debian', '13'],
]);

it('installs the pinned docker release with live restore and without touching the firewall', function () {
    config(['databasify.docker' => ['version' => '29.9.2', 'containerd' => '2.4.2']]);
    $ssh = fakeSsh()->respond('{"os":"Ubuntu 26.04","id":"ubuntu","version_id":"26.04","cpus":2,"memory_mb":4000,"disk_gb":80}'."\n");
    $server = Server::factory()->create();

    resolve(ProvisionServer::class)->handle($server, false);

    $docker = $ssh->scriptAt(5);
    $daemon = json_decode(Str::between($docker, "docker_settings=\$(cat <<'JSON'\n", "\nJSON\n"), true);
    expect($daemon)->toBe([
        'live-restore' => true,
        'iptables' => false,
        'ip6tables' => false,
        'bridge' => 'none',
        'log-driver' => 'json-file',
        'log-opts' => ['max-size' => '50m', 'max-file' => '5'],
    ])
        ->and($docker)
        ->toContain('URIs: https://download.docker.com/linux/${ID}')
        ->toContain("engine=\$(docker_build docker-ce '5:29.9.2-')")
        ->toContain("containerd=\$(docker_build containerd.io '2.4.2-')")
        ->toContain('apt-mark hold docker-ce docker-ce-cli containerd.io')
        ->toContain("if [ \"\$(docker info --format '{{.LiveRestoreEnabled}}')\" != true ]; then")
        ->and(strpos($docker, '/etc/docker/daemon.json'))->toBeLessThan(strpos($docker, 'apt-get install -yq --no-install-recommends'))
        ->and(collect($ssh->runs)->map(fn (array $run) => bashErrorsIn($run['script']))->filter()->all())->toBe([]);
});

it('marks the server failed and stops at the step that fails', function () {
    fakeSsh()->respond('{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":1,"memory_mb":1024,"disk_gb":25}'."\n")->respond()->respond()->respond("apt failed\n", 100);
    $server = Server::factory()->create();

    expect(fn () => resolve(ProvisionServer::class)->handle($server, false))
        ->toThrow(RemoteScriptFailed::class, "Updating packages on {$server->name} failed with exit code 100.");
    expect($server->fresh()->status)->toBe(ServerStatus::Failed)
        ->and($server->serverLogs()->pluck('name')->last())->toBe('Updating packages')
        ->and($server->serverLogs()->where('name', 'Updating packages')->first())
        ->status->toBe(ServerLogStatus::Failed)
        ->exit_code->toBe(100)
        ->output->toBe("apt failed\n");
});

it('keeps password login when not asked to disable it', function () {
    $ssh = fakeSsh()->respond('{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":1,"memory_mb":1024,"disk_gb":25}'."\n");
    $server = Server::factory()->create();

    resolve(ProvisionServer::class)->handle($server, false);

    expect($ssh->runs)->toHaveCount(7)
        ->and(collect($ssh->runs)->pluck('script')->implode("\n"))->not->toContain('PasswordAuthentication no')
        ->and($server->serverLogs()->where('name', 'Turning off password login')->exists())->toBeFalse()
        ->and($server->fresh()->status)->toBe(ServerStatus::Ready);
});

it('marks the server failed and installs nothing when reading facts fails', function () {
    $ssh = fakeSsh()->respond("no facts\n", 1);
    $server = Server::factory()->create(['ssh_password' => 'secret']);

    expect(fn () => resolve(ProvisionServer::class)->handle($server, false))
        ->toThrow(RemoteScriptFailed::class);
    expect($server->fresh())
        ->status->toBe(ServerStatus::Failed)
        ->ssh_password->toBe('secret')
        ->and($ssh->runs)->toHaveCount(1);
});

it('provisions the server when the queued job runs', function () {
    $ssh = fakeSsh()->respond('{"os":"Debian 12","id":"debian","version_id":"12","cpus":4,"memory_mb":8000,"disk_gb":80}'."\n");

    $server = resolve(AddServer::class)->handle(new NewServer('db-1', '203.0.113.10', 22, 'root', null, false));

    expect($server->fresh())
        ->status->toBe(ServerStatus::Ready)
        ->os->toBe('Debian 12')
        ->and($server->serverLogs()->where('name', 'Adding swap')->value('status'))->toBe(ServerLogStatus::Succeeded)
        ->and($ssh->runs)->toHaveCount(7);
});
