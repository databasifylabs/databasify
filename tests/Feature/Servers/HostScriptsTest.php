<?php

declare(strict_types=1);

use App\DTOs\Networks\WireguardPeer;
use App\Enums\Servers\OsFamily;
use App\Services\Ssh\Ssh;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * @return array<string, string>
 */
function renderedHostScripts(): array
{
    $provision = ['aptTimeout' => Ssh::APT_LOCK_TIMEOUT, 'user' => 'root', 'publicKey' => 'ssh-ed25519 AAAA app', 'privateIp' => '10.0.0.2', 'dockerVersion' => '29.9.2', 'containerdVersion' => '2.4.2'];
    $wireguard = [
        'address' => '100.64.0.1', 'range' => '100.64.0.0/24', 'prefix' => '24', 'listenPort' => 51820,
        'peers' => [new WireguardPeer('KEY-2', '100.64.0.2', '203.0.113.2', '203.0.113.2:51820')], 'memberIps' => ['203.0.113.2'],
    ];
    $scripts = [];

    foreach (OsFamily::cases() as $family) {
        $scripts["install base tools {$family->value}"] = view("ssh.servers.os.{$family->value}.install-base-tools", [...$provision, 'family' => $family])->render();
        $scripts["install docker {$family->value}"] = view("ssh.servers.os.{$family->value}.install-docker", [...$provision, 'family' => $family])->render();
    }

    return [
        ...$scripts,
        'facts' => view('ssh.servers.provision.facts')->render(),
        'require private ip' => view('ssh.servers.provision.require-private-ip', $provision)->render(),
        'install key' => view('ssh.servers.users.install-key', $provision)->render(),
        'update packages' => view('ssh.servers.os.update-packages', ['aptTimeout' => Ssh::APT_LOCK_TIMEOUT])->render(),
        'swap' => view('ssh.servers.os.swap', $provision)->render(),
        'disable password login' => view('ssh.servers.users.disable-password-login', $provision)->render(),
        'create user' => view('ssh.servers.users.create', ['username' => 'deploy', 'publicKey' => 'ssh-ed25519 AAAA me'])->render(),
        'firewall service' => view('ssh.servers.firewall.service')->render(),
        'wireguard join' => view('ssh.servers.wireguard.join', $wireguard)->render(),
        'wireguard peers' => view('ssh.servers.wireguard.peers', $wireguard)->render(),
        'wireguard leave' => view('ssh.servers.wireguard.leave')->render(),
    ];
}

function runWithFirewalld(string $calls, bool $active = true, bool $serviceExists = false): string
{
    $bin = sys_get_temp_dir().'/firewalld-'.uniqid();
    $state = $active ? 0 : 252;
    $infoService = $serviceExists ? 0 : 101;
    mkdir($bin);
    file_put_contents("{$bin}/firewall-cmd", <<<SH
        #!/bin/bash
        echo "\$*" >> {$bin}/calls
        case "\$*" in
            --state) exit {$state} ;;
            --get-default-zone) echo public ;;
            --get-active-zones) printf 'public\\n  interfaces: eth0\\ntrusted\\n  interfaces: databasify\\n' ;;
            "--permanent --get-zones") echo 'block public trusted' ;;
            --permanent\ --info-service=*) exit {$infoService} ;;
            --permanent\ --get-zone-of-interface=*) echo public ;;
        esac
        SH);
    file_put_contents("{$bin}/flock", "#!/bin/bash\necho \"flock \$*\" >> {$bin}/calls\n");
    chmod("{$bin}/firewall-cmd", 0755);
    chmod("{$bin}/flock", 0755);
    $backup = "{$bin}/databasify_database_7.xml.old";

    if ($serviceExists) {
        touch($backup);
    }

    $script = str_replace(
        ['/run/databasify-firewalld.lock', '/etc/firewalld/services/'],
        ["{$bin}/lock", "{$bin}/"],
        view('ssh.servers.firewall.firewalld')->render(),
    )."\n{$calls}\n";

    Process::env(['PATH' => "{$bin}:".getenv('PATH')])->input($script)->run(['bash', '-euo', 'pipefail'])->throw();
    $log = (is_file("{$bin}/calls") ? (string) file_get_contents("{$bin}/calls") : '').(is_file($backup) ? "kept {$backup}\n" : '');
    Process::run(['rm', '-rf', $bin]);

    return $log;
}

it('renders every host script variant as valid bash', function () {
    $errors = collect(renderedHostScripts())
        ->map(fn (string $script): string => bashErrorsIn($script))
        ->filter();

    expect($errors->all())->toBe([]);
});

it('detects the package family from os-release, falling back on ID_LIKE', function (string $osRelease, string $family) {
    $file = tempnam(sys_get_temp_dir(), 'os-release');
    file_put_contents($file, $osRelease);
    $script = str_replace('/etc/os-release', $file, view('ssh.servers.os.packages')->render())."\nos_family\n";

    $result = Process::input($script)->run(['bash']);
    File::delete($file);

    expect($result->output())->toBe("{$family}\n");
})->with([
    'ubuntu' => ["ID=ubuntu\nID_LIKE=debian\nVERSION_ID=\"24.04\"\n", 'debian'],
    'debian' => ["ID=debian\nVERSION_ID=\"13\"\n", 'debian'],
    'rocky' => ["ID=\"rocky\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"9.6\"\n", 'rhel'],
    'almalinux' => ["ID=\"almalinux\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"9.6\"\n", 'rhel'],
    'something else' => ["ID=alpine\nVERSION_ID=3.22\n", 'unknown'],
]);

it('opens the database ports as a permanent firewalld service in every active zone', function () {
    $calls = runWithFirewalld("firewalld_open databasify_database_7 '5432,6432,60000:61000/tcp'");

    expect($calls)
        ->toContain("--permanent --new-service=databasify_database_7\n")
        ->toContain("--permanent --service=databasify_database_7 --add-port=5432/tcp\n")
        ->toContain("--permanent --service=databasify_database_7 --add-port=6432/tcp\n")
        ->toContain("--permanent --service=databasify_database_7 --add-port=60000-61000/tcp\n")
        ->toContain("--permanent --zone=public --add-service=databasify_database_7\n")
        ->not->toContain('--zone=trusted --add-service')
        ->toEndWith("--reload\n");
});

it('trusts the wireguard interface and opens its port when firewalld runs', function () {
    $calls = runWithFirewalld("firewalld_trust databasify\nfirewalld_open databasify-network '51820/udp'");

    expect($calls)
        ->toContain("--permanent --zone=trusted --change-interface=databasify\n")
        ->toContain("--permanent --service=databasify-network --add-port=51820/udp\n");
});

it('serializes firewalld changes so overlapping runs on one host cannot race on a service', function () {
    $calls = runWithFirewalld("firewalld_trust databasify\nfirewalld_open databasify-network '51820/udp'\nfirewalld_close databasify-network\nfirewalld_distrust databasify");

    expect(substr_count($calls, "flock -w 300 9\n"))->toBe(4)
        ->and(strpos($calls, 'flock'))->toBeLessThan(strpos($calls, '--change-interface'))
        ->and(strpos($calls, '--change-interface'))->toBeLessThan(strpos($calls, '--new-service'));
});

it('deletes an existing service with its firewalld backup file', function () {
    $calls = runWithFirewalld('firewalld_close databasify_database_7', serviceExists: true);

    expect($calls)
        ->toContain("--permanent --zone=public --remove-service=databasify_database_7\n")
        ->toContain("--permanent --delete-service=databasify_database_7\n")
        ->not->toContain('kept ');
});

it('leaves the host alone when firewalld is not running', function () {
    $calls = runWithFirewalld("firewalld_open databasify_database_7 '5432/tcp'\nfirewalld_close databasify_database_7\nfirewalld_trust databasify", active: false);

    expect($calls)->toBe("--state\n--state\n--state\n");
});

it('keeps existing docker daemon settings and only reports a change when it writes one', function () {
    $dir = sys_get_temp_dir().'/docker-daemon-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/daemon.json", '{"registry-mirrors":["https://mirror.example"],"iptables":true}');
    $script = str_replace('/etc/docker', $dir, view('ssh.servers.docker.daemon')->render())."\necho \"changed=\$docker_daemon_changed\"\n";

    $first = Process::input($script)->run(['bash', '-euo', 'pipefail'])->throw()->output();
    $written = json_decode((string) file_get_contents("{$dir}/daemon.json"), true);
    $second = Process::input($script)->run(['bash', '-euo', 'pipefail'])->throw()->output();
    file_put_contents("{$dir}/daemon.json", 'not json');
    $broken = Process::input($script)->run(['bash', '-euo', 'pipefail']);
    Process::run(['rm', '-rf', $dir]);

    expect($first)->toBe("changed=1\n")
        ->and($written)->toMatchArray(['registry-mirrors' => ['https://mirror.example'], 'iptables' => false, 'live-restore' => true, 'bridge' => 'none'])
        ->and($second)->toBe("changed=0\n")
        ->and($broken->exitCode())->toBe(1)
        ->and($broken->output())->toContain('daemon.json is not a JSON object');
});

it('installs new dependencies such as a security kernel when updating apt packages', function () {
    $bin = sys_get_temp_dir().'/apt-'.uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/apt-get", "#!/bin/bash\necho \"\$*\" >> {$bin}/calls\n");
    chmod("{$bin}/apt-get", 0755);

    $result = Process::env(['PATH' => "{$bin}:".getenv('PATH')])->input(view('ssh.servers.os.update-packages', ['aptTimeout' => Ssh::APT_LOCK_TIMEOUT])->render())->run(['bash', '-euo', 'pipefail'])->throw();
    $calls = (string) file_get_contents("{$bin}/calls");
    Process::run(['rm', '-rf', $bin]);

    expect($calls)->toContain("upgrade -yq --with-new-pkgs\n")
        ->and($result->output())->toEndWith("packages are up to date\n");
});
