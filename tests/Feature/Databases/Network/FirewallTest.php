<?php

declare(strict_types=1);

use App\Actions\Databases\Network\AddTrustedSource;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Network\ApplyTrustedSources;
use App\Actions\Databases\Network\RemoveNodeFirewall;
use App\DTOs\Databases\NewTrustedSource;
use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use Illuminate\Support\Facades\Queue;

it('applies trusted sources atomically with merged ranges and a rule for the private network', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $node = $database->seed();
    $ssh = fakeSsh();

    resolve(ApplyNodeFirewall::class)->handle($database, $node, ['203.0.113.0/24', '203.0.113.7/32', '203.0.113.7/32']);

    $script = $ssh->scriptAt(0);
    expect($script)->toContain("elements = { %s } }\\n' '203.0.113.0/24, 203.0.113.7/32'")
        ->and($script)->toContain('auto-merge')->toContain("printf 'delete table inet %s\\n'")->not->toContain('flush table')
        ->and($script)->toContain('nft -c -f "$file.next"')->toContain('ip saddr %s tcp dport')
        ->and($script)->toContain("vpc_subnet='10.0.0.0/8'")->not->toContain('ip -4 route show default')
        ->and($script)->toContain('set peers')->toContain($node->server->public_ip)->toContain("tcp dport { %s } drop\\n' '33061'")
        ->and(strpos($script, 'nft -c -f'))->toBeLessThan(strpos($script, 'nft -f "$file"'));
});

it('keeps peer ports closed to strangers even without trusted sources, and removes the table on teardown', function () {
    $database = idleDatabase('valkey', 2);
    [$first, $second] = $database->nodes()->with('server')->get()->all();
    $ssh = fakeSsh();

    resolve(ApplyNodeFirewall::class)->handle($database, $first, []);
    resolve(RemoveNodeFirewall::class)->handle($database, $first);

    [$open, $removal] = [$ssh->scriptAt(0), $ssh->scriptAt(1)];

    expect($open)->toContain('set peers')->toContain($second->server->public_ip)->toContain($second->privateIp())
        ->toContain("tcp dport { %s } drop\\n' '6379, 26379'")->not->toContain('set trusted')->toContain('no trusted sources')
        ->and($removal)->toContain('nft delete table inet')->not->toContain('set peers');
});

it('leaves out the peer port rules when the engine has no peer-only ports', function () {
    $database = idleDatabase('mysql', 2);
    $script = view('ssh.databases.network.trusted-sources', [
        'table' => $database->firewallTable(),
        'remove' => false,
        'ports' => [25060],
        'peerPorts' => [],
        'peers' => $database->peerAddresses(),
        'sources' => ['203.0.113.7'],
        'vpcSubnet' => '10.0.0.0/8',
    ])->render();

    expect($script)->not->toContain('tcp dport {  }')->not->toContain('peer ports')
        ->toContain('set peers')->toContain("tcp dport { %s } ip saddr @peers accept\\n' '25060'")
        ->and(bashErrorsIn($script))->toBe('');
});

it('writes nftables rules for trusted sources and keeps the private network open', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([ApplyTrustedSourcesJob::class]);
    resolve(AddTrustedSource::class)->handle($database, NewTrustedSource::normalized('203.0.113.7', 'office'));
    $ssh = fakeSsh();

    resolve(ApplyTrustedSources::class)->handle($database);

    $script = $ssh->scriptAt(0);
    expect($database->trustedSources()->sole()->cidr)->toBe('203.0.113.7/32')
        ->and($script)->toContain('table=databasify_database_'.str_replace('-', '', $database->id))
        ->and($script)->toContain("elements = { %s } }\\n' '203.0.113.7/32'")
        ->and($script)->toContain("tcp dport { %s } drop\\n' '25061'")
        ->and($script)->toContain("vpc_subnet='10.0.0.0/8'")
        ->and($script)->toContain('systemctl enable databasify-firewall.service');
    Queue::assertPushed(ApplyTrustedSourcesJob::class);
});

it('removes the boot-time firewall service once the last rules file leaves the server', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $node = $database->seed();
    $ssh = fakeSsh();

    resolve(ApplyNodeFirewall::class)->handle($database, $node, []);
    resolve(RemoveNodeFirewall::class)->handle($database, $node);

    [$open, $removal] = [$ssh->scriptAt(0), $ssh->scriptAt(1)];
    $leave = view('ssh.servers.wireguard.leave')->render();

    expect($open)->toContain('systemctl enable databasify-firewall.service')->not->toContain('systemctl disable --now databasify-firewall.service')
        ->and($removal)->not->toContain('systemctl enable databasify-firewall.service')
        ->and($removal)->toContain("find /etc/databasify/nftables -maxdepth 1 -name '*.nft'")->toContain('systemctl disable --now databasify-firewall.service')->toContain('rm -f /etc/systemd/system/databasify-firewall.service')
        ->and(strpos($removal, 'rm -f "$file"'))->toBeLessThan(strpos($removal, 'systemctl disable --now databasify-firewall.service'))
        ->and($leave)->toContain('systemctl disable --now databasify-firewall.service')->not->toContain('unzip');
});
