<?php

declare(strict_types=1);

use App\Engines\Data\Member;
use App\Engines\Data\MigrationSource;
use App\Engines\Drivers\Sentinel\SentinelFamilyEngine;
use App\Engines\EngineClient;
use App\Engines\Requests\AccountList;
use App\Engines\Requests\ArchiveStatus;
use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\ClusterFailover;
use App\Engines\Requests\ClusterForceQuorum;
use App\Engines\Requests\ClusterMembership;
use App\Engines\Requests\ClusterQuorum;
use App\Engines\Requests\ClusterRejoin;
use App\Engines\Requests\ConfigApply;
use App\Engines\Requests\ConfigShow;
use App\Engines\Requests\MemberForget;
use App\Engines\Requests\MigrationCheck;
use App\Engines\Requests\MigrationStart;
use App\Engines\Requests\NodeMetrics;
use App\Engines\Requests\NodeReady;
use App\Engines\Requests\NodeReset;
use App\Engines\Requests\NodeRestart;
use App\Engines\Requests\NodeStart;
use App\Engines\Requests\NodeStatus;
use App\Engines\Scripts\ScriptView;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

beforeEach(function () {
    config(['databasify.php_engines' => ['valkey', 'redis']]);
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

function sendToSentinel(DatabaseNode $node, object $request): object
{
    return resolve(EngineClient::class)->send($node, $request);
}

it('renders every Valkey and Redis script to valid bash', function (string $engine) {
    $node = sentinelNode($engine);
    $invalid = [];

    foreach (Finder::create()->files()->in(resource_path('views/engines/sentinel'))->name('*.blade.php')->notName('_*') as $script) {
        $name = 'sentinel/'.str_replace('.blade.php', '', $script->getRelativePathname());
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
    $this->scripts->respond('sentinel/nodes/primary', ['primary' => '10.0.0.2'])->respond('sentinel/nodes/configure', ['changed' => true]);

    $reply = sendToSentinel(sentinelNode('redis'), configureFromFixture($fixture['request']));

    expect($reply->changed)->toBeTrue();
    $this->scripts->assertRan('sentinel/nodes/primary', fn (string $script, array $env): bool => $env['PEERS'] === '10.0.0.2' && str_contains($script, 'get-master-addr-by-name main'))
        ->assertRan('sentinel/nodes/configure', fn (string $script, array $env): bool => base64_decode($env['SERVER_CONF']) === $fixture['serverConf']
            && base64_decode($env['SENTINEL_CONF']) === $fixture['sentinelConf']
            && base64_decode($env['MANIFEST']) === $fixture['manifest']
            && $env['PASSWORD'] === 'pw' && $env['GUARD_EMPTY_PRIMARY'] === 'false'
            && str_contains($script, 'refusing to start as an empty primary while a peer still holds the data: move the primary to that peer first'));
});

it('refuses a node without the admin secret with the Go wording', function () {
    $request = goFixture('configure-valkey-standalone.json', 'sentinel')['request'];
    $request['secrets']['admin'] = null;

    expect(fn () => sendToSentinel(sentinelNode(), configureFromFixture($request)))->toThrow(InvalidEngineRequest::class, 'valkey needs the admin secret');
});

it('reads status and metrics', function () {
    $this->scripts->respond('sentinel/nodes/status', ['role' => 'replica', 'version' => '9.1.3'])->respond('sentinel/nodes/metrics', ['connections' => 2, 'queries' => 7, 'dataMb' => 1]);
    $node = sentinelNode();

    expect(sendToSentinel($node, new NodeStatus)->role)->toBe(NodeRole::Replica)
        ->and(sendToSentinel($node, new NodeMetrics)->queries)->toBe(7);
    $this->scripts->assertRan('sentinel/nodes/status', fn (string $script): bool => str_contains($script, "'valkey_version'") && str_contains($script, 'master_sync_in_progress'));
});

it('applies live settings without the restart-only ones and shows pending restarts', function () {
    $node = sentinelNode('redis', 'redis-pair');
    $this->scripts->respond('sentinel/config/show', ['values' => ['io-threads' => '4', 'databases' => '16', 'maxmemory' => '0']]);

    sendToSentinel($node, new ConfigApply);
    $show = sendToSentinel($node, new ConfigShow);

    expect($show->pendingRestart)->toBe(['io-threads']);
    $this->scripts->assertRan('sentinel/config/apply', function (string $script, array $env): bool {
        $desired = json_decode($env['DESIRED'], true);

        return $desired['timeout'] === '30' && $desired['maxmemory'] === '2867mb' && $desired['appendonly'] === 'yes' && $desired['save'] === '3600 1'
            && ! isset($desired['io-threads'])
            && json_decode($env['CONFIG_KEYS'], true)['pubsub-output-buffer-limit'] === 'client-output-buffer-limit'
            && str_contains($script, 'could not set $name: ');
    });
});

it('runs start, restart, reset and ready like Go', function () {
    $node = sentinelNode('valkey', 'valkey-replica');

    sendToSentinel($node, new NodeStart);
    sendToSentinel($node, new NodeRestart);
    sendToSentinel($node, new NodeReset(2, ['10.0.0.1', '10.0.0.2']));
    sendToSentinel($node, new NodeReady('ready.1', false));

    $this->scripts->assertRan('sentinel/nodes/start', fn (string $script, array $env): bool => $env['HOLDS'] === 'false' && str_contains($script, 'start-refused'))
        ->assertRan('sentinel/nodes/restart', fn (string $script, array $env): bool => collect(json_decode($env['DIRECTIVES'], true))->firstWhere('name', 'databases') === ['name' => 'databases', 'line' => null])
        ->assertRan('sentinel/nodes/reset', fn (string $script, array $env): bool => $env['KEEP'] === '127.0.0.1 10.0.0.1 10.0.0.2' && $env['PEERS'] === '2')
        ->assertRan('sentinel/nodes/ready', fn (string $script, array $env): bool => $env['READ_ONLY'] === 'true' && str_contains($script, "phase 'following the primary'") && str_contains($script, "phase 'waiting for TLS'"));
});

it('runs the cluster commands', function () {
    $node = sentinelNode('redis', 'redis-primary');
    $this->scripts->respond('sentinel/cluster/quorum', ['total' => 3, 'online' => 3]);

    sendToSentinel($node, new ClusterEnable('e.1'));
    sendToSentinel($node, new ClusterFailover('10.0.0.2'));
    sendToSentinel($node, new ClusterForceQuorum(false));
    sendToSentinel($node, new ClusterMembership([new Member('10.0.0.1', 1, true, 2, 4096, 80), new Member('10.0.0.2', 2, true, 2, 4096, 80)]));
    sendToSentinel($node, new ClusterRejoin('r.1', false));
    expect(sendToSentinel($node, new ClusterQuorum)->online)->toBe(3);

    $this->scripts->assertRan('sentinel/cluster/enable', fn (string $script, array $env): bool => $env['REQUIRED'] === '1')
        ->assertRan('sentinel/cluster/failover', fn (string $script, array $env): bool => str_contains($script, 'is a read-only replica and cannot become the primary') && str_contains($script, 'SENTINEL FAILOVER main'))
        ->assertRan('sentinel/cluster/force-quorum', fn (string $script): bool => str_contains($script, 'sentinel config-epoch main %d'))
        ->assertRan('sentinel/cluster/membership', fn (string $script, array $env): bool => $env['QUORUM'] === '2' && $env['REQUIRED'] === '0')
        ->assertRan('sentinel/cluster/rejoin', fn (string $script): bool => str_contains($script, "phase 'finding the primary'"));
});

it('checks and starts a redis-shake migration', function () {
    $node = sentinelNode('redis', 'redis-pair');
    $this->scripts->respond('sentinel/migration/check', ['error' => null, 'version' => '7.2.4', 'sizeMb' => 3, 'warnings' => ['the source loads the search module, whose data types this server cannot store']]);

    $check = sendToSentinel($node, new MigrationCheck(new MigrationSource('10.0.0.9', 6380, 'default', 'p"w', true), []));
    sendToSentinel($node, new MigrationStart(new MigrationSource('10.0.0.9', 6380, 'default', 'p"w', true), [], false, null));

    expect($check->canReplicate)->toBeTrue()->and($check->sizeMb)->toBe(3);
    $this->scripts->assertRan('sentinel/migration/check', fn (string $script, array $env): bool => $env['SOURCE_USER'] === '' && $env['SOURCE_TLS'] === 'true')
        ->assertRan('sentinel/migration/start', fn (string $script, array $env): bool => str_contains(base64_decode($env['SHAKE']), 'password = "p\\"w"') && str_contains($script, 'WorkingDirectory='));
});

it('answers the commands Go does not implement as unsupported', function (object $request, string $command) {
    expect(fn () => sendToSentinel(sentinelNode(), $request))->toThrow(EngineUnsupported::class, "this engine does not implement \"{$command}\"");
})->with([
    'accounts' => [new AccountList, 'account list'],
    'archive' => [new ArchiveStatus(false), 'archive status'],
    'forget' => [new MemberForget('10.0.0.2'), 'member forget'],
]);
