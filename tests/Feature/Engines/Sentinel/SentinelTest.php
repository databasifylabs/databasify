<?php

declare(strict_types=1);

use App\Engines\Contracts\EngineDriver;
use App\Engines\Data\Member;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart as MigrationStartData;
use App\Engines\Drivers\Redis\RedisDriver;
use App\Engines\Drivers\Sentinel\SentinelFamilyEngine;
use App\Engines\Drivers\Valkey\ValkeyDriver;
use App\Engines\Scripts\ScriptView;
use App\Enums\Databases\NodeRole;
use App\Enums\Engines\Feature;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;
use App\Plugins\RegisterEngine;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;
use Tests\Support\KeyDbDriver;

beforeEach(function () {
    $this->scripts = fakeScripts();
});

function sentinelNode(string $engine = 'valkey', ?string $case = null): DatabaseNode
{
    if ($case !== null) {
        $ssh = respondToFileGet(fakeSsh(), 'state/node.json', json_encode(goFixture("configure-{$case}.json", 'sentinel')['request']));
        respondToFileGet($ssh, 'state/tuned.json', '{"io-threads":"1","maxclients":"65504","maxmemory":"2867mb"}');
    }

    return idleDatabase($engine, 1, name: uniqid('db'), versionLine: $engine === 'valkey' ? '9' : '8')->nodes()->sole();
}

it('renders every Valkey and Redis script to valid bash', function (string $engine) {
    $node = sentinelNode($engine);
    $invalid = [];

    foreach (Finder::create()->files()->in(resource_path('views/engines/sentinel'))->notPath('files')->name('*.blade.php')->notName('_*') as $script) {
        $name = 'engines.sentinel.'.str_replace(['.blade.php', '/'], ['', '.'], $script->getRelativePathname());
        $check = Process::input(resolve(ScriptView::class)->render($node, $name, ['engine' => SentinelFamilyEngine::of($engine)]))->run(['bash', '-n']);

        if (! $check->successful()) {
            $invalid[] = "{$name}: {$check->errorOutput()}";
        }
    }

    expect($invalid)->toBe([]);
})->with(['valkey', 'redis']);

it('configures with the primary found by the peers and the Go files', function () {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $fixture = goFixture('configure-redis-pair.json', 'sentinel');
    $this->scripts->respond('engines.sentinel.nodes.primary', ['primary' => '10.0.0.2'])->respond('engines.sentinel.nodes.configure', ['changed' => true]);

    sentinelNode('redis')->driver()->nodes()->configure(nodeConfigFromFixture($fixture['request']));

    $this->scripts->assertRan('engines.sentinel.nodes.primary', fn (string $script, array $env): bool => $env['PEERS'] === '10.0.0.2' && $env['CLI_SECRET'] === 'pw' && str_contains($script, 'get-master-addr-by-name main'))
        ->assertRan('engines.sentinel.nodes.configure', fn (string $script, array $env): bool => base64_decode($env['SERVER_CONF']) === $fixture['serverConf']
            && base64_decode($env['SENTINEL_CONF']) === $fixture['sentinelConf']
            && base64_decode($env['MANIFEST']) === $fixture['manifest']
            && $env['PASSWORD'] === 'pw' && $env['GUARD_EMPTY_PRIMARY'] === 'false'
            && str_contains($script, 'refusing to start as an empty primary while a peer still holds the data: move the primary to that peer first'));
});

it('refuses a node without the admin secret with the Go wording', function () {
    $request = goFixture('configure-valkey-standalone.json', 'sentinel')['request'];
    $request['secrets']['admin'] = null;

    expect(fn () => sentinelNode()->driver()->nodes()->configure(nodeConfigFromFixture($request)))->toThrow(InvalidEngineRequest::class, 'valkey needs the admin secret');
});

it('reads status and metrics', function () {
    $this->scripts->respond('engines.sentinel.nodes.status', ['role' => 'replica', 'version' => '9.1.3'])->respond('engines.sentinel.nodes.metrics', ['connections' => 2, 'queries' => 7, 'dataMb' => 1]);
    $node = sentinelNode();

    expect($node->driver()->nodes()->status()->role)->toBe(NodeRole::Replica)
        ->and($node->driver()->nodes()->metrics()->queries)->toBe(7);
    $this->scripts->assertRan('engines.sentinel.nodes.status', fn (string $script): bool => str_contains($script, "'valkey_version'") && str_contains($script, 'master_sync_in_progress'));
});

it('applies live settings without the restart-only ones and shows pending restarts', function () {
    $node = sentinelNode('redis', 'redis-pair');
    $this->scripts->respond('engines.sentinel.config.show', ['values' => ['io-threads' => '4', 'databases' => '16', 'maxmemory' => '0']]);

    $node->driver()->config()->apply();
    $show = $node->driver()->config()->pendingRestart();

    expect($show)->toBe(['io-threads']);
    $this->scripts->assertRan('engines.sentinel.config.apply', function (string $script, array $env): bool {
        $desired = json_decode($env['DESIRED'], true);

        return $desired['timeout'] === '30' && $desired['maxmemory'] === '2867mb' && $desired['appendonly'] === 'yes' && $desired['save'] === '3600 1'
            && ! isset($desired['io-threads'])
            && json_decode($env['CONFIG_KEYS'], true)['pubsub-output-buffer-limit'] === 'client-output-buffer-limit'
            && str_contains($script, 'could not set $name: ');
    });
});

it('runs start, restart, reset and ready like Go', function () {
    $node = sentinelNode('valkey', 'valkey-replica');

    $node->driver()->nodes()->start();
    $node->driver()->nodes()->restart();
    $node->driver()->nodes()->reset(2, ['10.0.0.1', '10.0.0.2']);
    $node->driver()->nodes()->ready('ready.1', false);

    $this->scripts->assertRan('engines.sentinel.nodes.start', fn (string $script, array $env): bool => $env['HOLDS'] === 'false' && str_contains($script, 'start-refused'))
        ->assertRan('engines.sentinel.nodes.restart', fn (string $script, array $env): bool => collect(json_decode($env['DIRECTIVES'], true))->firstWhere('name', 'databases') === ['name' => 'databases', 'line' => null])
        ->assertRan('engines.sentinel.nodes.reset', fn (string $script, array $env): bool => $env['KEEP'] === '127.0.0.1 10.0.0.1 10.0.0.2' && $env['PEERS'] === '2')
        ->assertRan('engines.sentinel.nodes.ready', fn (string $script, array $env): bool => $env['READ_ONLY'] === 'true' && str_contains($script, "phase 'following the primary'") && str_contains($script, "phase 'waiting for TLS'"));
});

it('runs the cluster commands', function () {
    $node = sentinelNode('redis', 'redis-primary');
    $this->scripts->respond('engines.sentinel.cluster.quorum', ['total' => 3, 'online' => 3]);

    $node->driver()->cluster()->enable('e.1');
    $node->driver()->cluster()->failover('10.0.0.2');
    $node->driver()->cluster()->forceQuorum(false);
    $node->driver()->cluster()->membership([new Member('10.0.0.1', 1, true, 2, 4096, 80), new Member('10.0.0.2', 2, true, 2, 4096, 80)]);
    $node->driver()->cluster()->rejoin('r.1', false);
    expect($node->driver()->cluster()->quorum()->online)->toBe(3);

    $this->scripts->assertRan('engines.sentinel.cluster.enable', fn (string $script, array $env): bool => $env['REQUIRED'] === '1')
        ->assertRan('engines.sentinel.cluster.failover', fn (string $script, array $env): bool => str_contains($script, 'is a read-only replica and cannot become the primary') && str_contains($script, 'SENTINEL FAILOVER main') && str_contains($script, 'if info=$(replication_info); then'))
        ->assertRan('engines.sentinel.cluster.force-quorum', fn (string $script): bool => str_contains($script, 'sentinel config-epoch main %d'))
        ->assertRan('engines.sentinel.cluster.membership', fn (string $script, array $env): bool => $env['QUORUM'] === '2' && $env['REQUIRED'] === '0')
        ->assertRan('engines.sentinel.cluster.rejoin', fn (string $script): bool => str_contains($script, "phase 'finding the primary'"));
});

it('checks and starts a redis-shake migration', function () {
    $node = sentinelNode('redis', 'redis-pair');
    $this->scripts->respond('engines.sentinel.migration.check', ['error' => null, 'version' => '7.2.4', 'sizeMb' => 3, 'warnings' => ['the source loads the search module, whose data types this server cannot store']]);

    $check = $node->driver()->migration()->check(new MigrationSource('10.0.0.9', 6380, 'default', 'p"w', true), []);
    $node->driver()->migration()->start(new MigrationStartData(new MigrationSource('10.0.0.9', 6380, 'default', 'p"w', true), [], false, null));

    expect($check->supportsContinuous())->toBeTrue()->and($check->facts['size_mb'])->toBe('3');
    $this->scripts->assertRan('engines.sentinel.migration.check', fn (string $script, array $env): bool => $env['SOURCE_USER'] === '' && $env['SOURCE_TLS'] === 'true')
        ->assertRan('engines.sentinel.migration.start', fn (string $script, array $env): bool => str_contains(base64_decode($env['SHAKE']), 'password = "p\\"w"') && str_contains($script, 'WorkingDirectory='));
});

it('answers the commands Go does not implement as unsupported', function (Closure $call, string $command) {
    expect(fn () => $call(sentinelNode()->driver()))->toThrow(EngineUnsupported::class, "this engine does not implement \"{$command}\"");
})->with([
    'accounts' => [fn (EngineDriver $driver) => $driver->accounts()->list(), 'user create'],
    'archive' => [fn (EngineDriver $driver) => $driver->archive()->status(false), 'archive status'],
    'forget' => [fn (EngineDriver $driver) => $driver->cluster()->forget('10.0.0.2'), 'member forget'],
]);

it('offers no accounts or archive', function (string $driver) {
    $facts = $driver::facts('9');

    expect($facts->offers(Feature::Accounts))->toBeFalse()
        ->and($facts->offers(Feature::Archive))->toBeFalse()
        ->and($facts->offers(Feature::Pools))->toBeFalse()
        ->and($facts->offers(Feature::Migration))->toBeTrue();
})->with([ValkeyDriver::class, RedisDriver::class]);

it('runs a plugin engine on the sentinel driver under its own id', function () {
    RegisterEngine::make('keydb')->label('KeyDB')->category('Key-value')->image('ghcr.io/acme/databasify-keydb')->versions('6', [['value' => '6', 'label' => 'KeyDB 6']])->driver(KeyDbDriver::class)->register();
    withImage('keydb', '6.3.4');

    idleDatabase('keydb', 1, versionLine: '6')->nodes()->sole()->driver()->nodes()->wake(false);

    $this->scripts->assertRan('engines.sentinel.nodes.wake', fn (string $script): bool => str_contains($script, 'keydb-cli') && str_contains($script, '/srv/keydb'));
});
