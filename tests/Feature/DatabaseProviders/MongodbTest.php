<?php

declare(strict_types=1);

use App\Actions\Databases\Recovery\EnablePointInTimeRecovery;
use App\DatabaseProviders\Drivers\Mongodb\Mongodb;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\NodeResources;
use App\DTOs\Recovery\RecoverySchedule;
use App\DTOs\Recovery\RecoverySettings;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Models\Database;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

it('runs mongod as a tls replica set with keyfile auth and tuned settings', function () {
    $database = idleDatabase('mongodb', 1, DatabaseMode::Single);
    $database->nodes()->sole()->server->update(['cpus' => null, 'memory_mb' => null, 'disk_gb' => null]);
    $database->update(['settings' => $database->settings->withConfig(['slowOpThresholdMs' => '250', 'verbosity' => '1', 'compressors' => 'zstd'])]);
    $config = json_decode(renderedFile($database->fresh(), 'mongod.conf'), true);

    expect($config['net'])->toMatchArray(['port' => 25060, 'bindIpAll' => true, 'maxIncomingConnections' => 512])
        ->and($config['net'])->not->toHaveKey('compression')
        ->and($config['systemLog'])->toBe(['verbosity' => 1])
        ->and($config['net']['tls'])->toBe(['mode' => 'requireTLS', 'certificateKeyFile' => '/srv/mongodb/tls/server.pem', 'CAFile' => '/srv/mongodb/tls/ca.crt', 'allowConnectionsWithoutCertificates' => true, 'disabledProtocols' => 'TLS1_0,TLS1_1'])
        ->and($config['security'])->toBe(['keyFile' => '/srv/mongodb/conf/keyfile', 'authorization' => 'enabled'])
        ->and($config['replication'])->toBe(['replSetName' => 'databasify'])
        ->and($config['storage'])->toBe(['dbPath' => '/srv/mongodb/data', 'wiredTiger' => ['engineConfig' => ['cacheSizeGB' => 0.25]]])
        ->and($config['operationProfiling'])->toBe(['slowOpThresholdMs' => 250]);
});

it('writes the keyfile for mongod and keeps the root password with root', function () {
    $database = idleDatabase('mongodb', 1, DatabaseMode::Single);
    $files = collect($database->provider()->configFiles($database->seed()))->keyBy(fn (NodeFile $file): string => basename($file->path));

    expect($database->settings->secrets)->toHaveKeys(['MONGODB_ROOT_PASSWORD', 'MONGODB_KEYFILE', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
        ->and($files['keyfile'])->mode->toBe('600')->owner->toBe('mongodb')->content->toBe($database->settings->secret('MONGODB_KEYFILE'))
        ->and($files['root-password'])->mode->toBe('600')->owner->toBe('root')
        ->and($files['mongod.conf'])->owner->toBe('mongodb')
        ->and($files['own-address'])->owner->toBe('mongodb')->content->toBe($database->seed()->privateIp().':25060');
});

it('sizes the cache at half of the memory left after a gigabyte and connections from memory', function () {
    expect((new Mongodb(new Database(['engine' => 'mongodb'])))->tunedSettings(new NodeResources(2, 4096, 80)))->toBe(['cacheSizeGB' => '1.50', 'maxIncomingConnections' => '2048'])
        ->and((new Mongodb(new Database(['engine' => 'mongodb'])))->tunedSettings(new NodeResources(1, 1024, 10)))->toBe(['cacheSizeGB' => '0.25', 'maxIncomingConnections' => '512']);
});

it('supervises mongod alone and leaves the glibc tunable to the image', function () {
    $database = idleDatabase('mongodb', 3);
    $manifest = $database->provider()->processes()->toArray();

    expect($manifest['processes'])->toHaveCount(1)
        ->and($manifest['processes'][0])->toMatchArray(['name' => 'mongod', 'user' => 'mongodb', 'restart' => 'on-failure', 'stopTimeout' => '5m'])
        ->and($manifest['processes'][0]['env'] ?? [])->not->toHaveKey('GLIBC_TUNABLES')
        ->and($manifest['processes'][0]['argv'][2])->toEndWith('exec mongod --config /srv/mongodb/conf/mongod.conf');
});

it('leaves mongod down without a crash loop until the deploy step recreates a lost data directory', function () {
    $command = idleDatabase('mongodb', 3)->provider()->processes()->toArray()['processes'][0]['argv'][2];
    $missing = sys_get_temp_dir().'/mongodb-data-'.uniqid();

    $result = Process::run(['sh', '-c', str_replace('/srv/mongodb/data ]', "{$missing} ]", $command)]);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('the data directory is missing');
});

it('reports roles from hello and maps scripts onto the replica set', function () {
    $engine = 'mongodb';
    $driver = Database::providerFor($engine);

    expect($driver->role("role:primary\nversion:8.0.34"))->toBe(NodeRole::Primary)
        ->and($driver->role('role:secondary'))->toBe(NodeRole::Replica)
        ->and($driver->role('role:unknown'))->toBe(NodeRole::Unknown)
        ->and(Database::providerFor($engine)->scriptView('lifecycle.wake'))->toBe('ssh.engines.mongodb.lines.8_0.lifecycle.wake')
        ->and(Database::providerFor($engine)->scriptView('cluster.rejoin'))->toBe('ssh.engines.mongodb.lines.8_0.cluster.rejoin')
        ->and(Database::providerFor($engine)->ports()->peers)->toBe([])
        ->and(Database::providerFor($engine)->tls()->files)->toBe(['ca.crt', 'server.pem']);
});

it('initiates the replica set and creates root only on a seed that starts a new set', function () {
    $database = idleDatabase('mongodb', 3);
    [$seed, $joining] = $database->nodes()->members()->get()->all();
    $render = fn ($node, bool $bootstraps): string => view(Database::providerFor('mongodb')->scriptView('lifecycle.start'), $database->provider()->scriptData()->starting($node, $bootstraps))->render();

    expect($render($seed, true))->toContain("rs.initiate({ _id: 'databasify', members: [{ _id: 0, host: '{$seed->privateIp()}:25060' }] })")->toContain("createUser({ user: 'root'")
        ->and($render($seed, false))->not->toContain('rs.initiate')
        ->and($render($joining, false))->not->toContain('rs.initiate')->not->toContain('createUser')
        ->and(bashErrorsIn($render($seed, true)))->toBe('');
});

it('applies the slow operation threshold, log verbosity and transaction lifetime live', function () {
    $database = idleDatabase('mongodb', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withConfig(['verbosity' => '2', 'transactionLifetimeLimitSeconds' => '90'])]);
    $script = view(Database::providerFor('mongodb')->scriptView('config.apply-config'), $database->fresh()->provider()->scriptData()->liveConfig($database->seed(), ['slowOpThresholdMs', 'verbosity', 'transactionLifetimeLimitSeconds']))->render();

    expect($script)->toContain("profile: -1, slowms: Number('100')")
        ->toContain("setParameter: 1, logLevel: Number('2')")
        ->toContain("setParameter: 1, transactionLifetimeLimitSeconds: Number('90')")
        ->not->toContain('apply_rw_concern ||')
        ->toContain('record_running_config')
        ->and(bashErrorsIn($script))->toBe('');
});

it('sets the default read and write concern on the primary only, never in mongod.conf', function () {
    $database = idleDatabase('mongodb', 3);
    $database->update(['settings' => $database->settings->withConfig(['defaultReadConcern' => 'majority', 'defaultWriteConcern' => '1'])]);
    $database = $database->fresh();
    $script = view(Database::providerFor('mongodb')->scriptView('config.apply-config'), $database->provider()->scriptData()->liveConfig($database->seed(), ['defaultReadConcern']))->render();
    $config = json_decode(renderedFile($database, 'mongod.conf'), true);

    expect(json_decode(renderedFile($database, 'rw-concern.json'), true))->toBe(['defaultReadConcern' => ['level' => 'majority'], 'defaultWriteConcern' => ['w' => 1]])
        ->and(json_decode(renderedFile(idleDatabase('mongodb', 1, DatabaseMode::Single, 'plain'), 'rw-concern.json'), true))->toBe(['defaultReadConcern' => ['level' => 'local'], 'defaultWriteConcern' => ['w' => 'majority']])
        ->and($config)->not->toHaveKey('defaultReadConcern')->not->toHaveKey('defaultWriteConcern')
        ->and($script)->toContain("if writable_primary; then\n    apply_rw_concern")->not->toContain('setParameter: 1, defaultReadConcern')
        ->and(view(Database::providerFor('mongodb')->scriptView('lifecycle.status'), $database->provider()->scriptData()->for($database->seed()))->render())->toContain('getDefaultRWConcern: 1')->toContain('setDefaultRWConcern: 1, ...rwWanted')
        ->and(view(Database::providerFor('mongodb')->scriptView('lifecycle.start'), $database->provider()->scriptData()->starting($database->seed(), true))->render())->toContain("if writable_primary; then\n    apply_rw_concern")
        ->and(bashErrorsIn($script))->toBe('');
});

it('offers exactly the five settings DigitalOcean offers and keeps tuning automatic', function () {
    expect(collect(Database::providerFor('mongodb')->fields())->map->key->all())->toBe(['defaultReadConcern', 'defaultWriteConcern', 'transactionLifetimeLimitSeconds', 'slowOpThresholdMs', 'verbosity']);
});

it('issues member certificates that also authenticate members to each other', function () {
    $database = idleDatabase('mongodb', 1, DatabaseMode::Single);
    $script = view('ssh.databases.certificates.generate', $database->provider()->scriptData()->certificates(['10.0.0.1']))->render();

    expect($script)->toContain('extendedKeyUsage=serverAuth,clientAuth')->toContain('cat server.crt server.key > server.pem');
});

it('hands apps the default account with the mongodb connection', function () {
    $database = idleDatabase('mongodb', 1, DatabaseMode::Single);

    expect($database->provider()->environment($database->endpoints()))->toMatchArray(['DB_CONNECTION' => 'mongodb', 'DB_PORT' => '25060', 'DB_DATABASE' => 'defaultdb', 'DB_USERNAME' => 'databasify'])
        ->and(Database::providerFor('mongodb')->connection()->uriQuery)->toBe('?tls=true&tlsAllowInvalidCertificates=true&authSource=admin&directConnection=true');
});

it('connects without a ca by default', function () {
    $profile = withZone(idleDatabase('mongodb', 1, DatabaseMode::Single))->provider()->connection();

    expect([$profile->command, $profile->service?->command, $profile->service?->standbyCommand])->each->toContain('tlsAllowInvalidCertificates=true')->not->toContain('{ca}')
        ->and([$profile->uriQuery, $profile->service?->uriQuery, $profile->service?->standbyUriQuery])->each->toContain('tlsAllowInvalidCertificates=true');
});

it('explains how to verify with the ca', function () {
    $profile = withZone(idleDatabase('mongodb', 1, DatabaseMode::Single))->provider()->connection();

    expect([$profile->envNote, $profile->commandNote, $profile->service?->envNote])->each->toContain('replace tlsAllowInvalidCertificates=true with tlsCAFile=')->toContain('{ca}')
        ->and($profile->service?->envNote)->toContain('Set tlsAllowInvalidCertificates=true in your MongoDB connection options.');
});

it('reads from secondaries through the mongodb+srv service on standby connections', function () {
    $service = withZone(idleDatabase('mongodb', 3))->provider()->connection()->toArray()['service'];

    expect($service)->toMatchArray([
        'uri_scheme' => 'mongodb+srv',
        'uri_query' => '?tls=true&tlsAllowInvalidCertificates=true',
        'uri_port' => false,
        'command' => 'mongosh "mongodb+srv://{username}:{password}@{host}/{database}?tlsAllowInvalidCertificates=true"',
        'standby_uri_query' => '?tls=true&tlsAllowInvalidCertificates=true&readPreference=secondaryPreferred',
        'standby_command' => 'mongosh "mongodb+srv://{username}:{password}@{host}/{database}?tlsAllowInvalidCertificates=true&readPreference=secondaryPreferred"',
    ]);
});

it('gives every member a vote and keeps read-only nodes out of elections', function () {
    $database = idleDatabase('mongodb', 3);
    $reader = withReadOnlyNode($database);
    $members = $database->fresh()->provider()->membershipVariables()['members'];

    expect($members)->toHaveCount(4)
        ->and(collect($members)->take(3)->every(fn (array $member): bool => $member['votes'] === 1 && $member['priority'] === 1))->toBeTrue()
        ->and($members[3])->toBe(['host' => "{$reader->privateIp()}:25060", 'votes' => 0, 'priority' => 0, 'alias' => null])
        ->and(json_decode(renderedFile($database->fresh(), 'members.json'), true))->toBe($members);
});

it('renders the replica set scripts as valid bash around valid mongosh javascript', function () {
    $database = idleDatabase('mongodb', 3);
    $engine = 'mongodb';
    $driver = Database::providerFor($engine);
    [$seed, $standby] = $database->nodes()->members()->get()->all();
    $render = fn (string $script, array $data): string => view(Database::providerFor($engine)->scriptView($script), $data)->render();
    $scripts = [
        'status' => $render('lifecycle.status', $database->provider()->scriptData()->for($seed)),
        'adopt' => $render('cluster.adopt', $database->provider()->scriptData()->admission($standby)),
        'membership' => $render('cluster.membership', [...$database->provider()->scriptData()->for(), ...$database->provider()->membershipVariables()]),
        'forget' => $render('cluster.forget', [...$database->provider()->scriptData()->for(), 'instance' => $driver->clientAddress($standby)]),
        'uncluster' => $render('cluster.uncluster', $database->provider()->scriptData()->clustering($seed)),
        'quorum' => $render('cluster.quorum', $database->provider()->scriptData()->for($seed)),
        'failover' => $render('cluster.failover', [...$database->provider()->scriptData()->for(), 'target' => $driver->clientAddress($standby)]),
        'force-quorum' => $render('cluster.force-quorum', [...$database->provider()->scriptData()->for($seed), 'instance' => $driver->clientAddress($seed), 'othersAreDown' => true]),
        'recovery-position' => $render('cluster.recovery-position', $database->provider()->scriptData()->for($seed)),
    ];
    $javascript = collect($scripts)->flatMap(function (string $script, string $name): array {
        preg_match_all("/<<'JS'[^\n]*\n(.*?)\nJS\n/s", $script, $blocks);

        return collect($blocks[1])->mapWithKeys(fn (string $block, int $index): array => ["{$name}#{$index}" => $block])->all();
    });

    expect(collect($scripts)->map(fn (string $script): string => bashErrorsIn($script))->filter()->all())->toBe([])
        ->and($javascript->keys()->all())->toContain('status#0', 'adopt#0', 'membership#0', 'forget#0', 'uncluster#0', 'failover#0', 'force-quorum#0')
        ->and($javascript->map(fn (string $block): string => javascriptErrorsIn($block))->filter()->all())->toBe([])
        ->and($scripts['adopt'])->toContain("const host = '{$standby->privateIp()}:25060';")->toContain('voters >= 7 ? 0 : 1')
        ->and($scripts['failover'])->toContain("const target = '{$standby->privateIp()}:25060';")->toContain('replSetStepDown: 60');
});

it('finishes a forced recovery once any member is primary, not only the forcing one', function () {
    $database = idleDatabase('mongodb', 3);
    $engine = 'mongodb';
    $driver = Database::providerFor($engine);
    $survivor = $database->seed();
    $script = view(Database::providerFor($engine)->scriptView('cluster.force-quorum'), [...$database->provider()->scriptData()->for($survivor), 'instance' => $driver->clientAddress($survivor), 'othersAreDown' => false])->render();

    expect($script)->toContain('wait_for 300 has_primary')->not->toContain('wait_for 300 writable_primary')
        ->and(bashErrorsIn($script))->toBe('');
});

it('forces a quorum only when no healthy majority of voters can elect a primary', function () {
    $database = idleDatabase('mongodb', 3);
    $survivor = $database->seed();
    $driver = $database->provider();
    $script = view(Database::providerFor('mongodb')->scriptView('cluster.force-quorum'), [...$driver->scriptData()->for($survivor), 'instance' => $driver->clientAddress($survivor), 'othersAreDown' => false])->render();
    preg_match("/<<'JS'[^\n]*\n(.*?)\nJS\n/s", $script, $block);

    expect($block[1])->toContain('while (!hasPrimary() && majorityIsHealthy() && Date.now() < electionDeadline)')
        ->toContain("} else if (majorityIsHealthy()) {\n    print('a majority of the voting members is healthy, the replica set elects its primary itself');")
        ->and(strpos($block[1], 'majorityIsHealthy()) {'))->toBeLessThan(strpos($block[1], 'rs.reconfig(config, { force: true })'))
        ->and(javascriptErrorsIn($block[1]))->toBe('');
});

it('adds members with their public name and publishes every alias once the database has a domain', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('mongodb', 3, name: 'orders'));
    $engine = 'mongodb';
    [$seed, $standby] = $database->nodes()->members()->get()->all();
    $adopt = view(Database::providerFor($engine)->scriptView('cluster.adopt'), $database->provider()->scriptData()->admission($standby))->render();
    $membership = view(Database::providerFor($engine)->scriptView('cluster.membership'), [...$database->provider()->scriptData()->for(), ...$database->provider()->membershipVariables()])->render();
    $status = view(Database::providerFor($engine)->scriptView('lifecycle.status'), $database->provider()->scriptData()->for($seed))->render();
    preg_match_all("/<<'JS'[^\n]*\n(.*?)\nJS\n/s", $adopt.$membership.$status, $blocks);

    expect($adopt)->toContain("...horizonsOf(\"orders-node-{$standby->position}.db.example.com:25060\")")
        ->and($membership)->toContain("\"alias\":\"orders-node-{$seed->position}.db.example.com:25060\"")->toContain('rs.reconfig(withHorizons(rs.conf(), wanted))')
        ->and($status)->toContain('horizonsDrifted(rs.conf(), wanted)')
        ->and(collect($blocks[1])->map(fn (string $block): string => javascriptErrorsIn($block))->filter()->all())->toBe([])
        ->and(idleDatabase('mongodb', 2, name: 'plain')->provider()->scriptData()->admission($standby)['alias'])->toBeNull();
});

it('offers 9.0 without point-in-time recovery because percona backup does not support it yet', function () {
    $lines = Database::providerFor('mongodb')->versions()->keyBy(fn ($line): string => $line->value);
    $nine = idleDatabase('mongodb', 1, DatabaseMode::Single, 'nine', '9.0');
    $storage = StorageProvider::factory()->create();

    expect($lines->keys()->all())->toBe(['7.0', '8.0', '9.0'])
        ->and(Database::providerFor('mongodb')->versions()->firstWhere('isDefault', true)?->value)->toBe('8.0')
        ->and($lines['9.0']->archives)->toBeFalse()
        ->and($lines['8.0']->archives)->toBeTrue()
        ->and($nine->recoveryUnavailableMessage())->toBe('Point-in-time recovery is not available for MongoDB 9.0 at this moment.')
        ->and($nine->archivesForRecovery())->toBeFalse()
        ->and(idleDatabase('mongodb', 1, DatabaseMode::Single, 'eight', '8.0')->archivesForRecovery())->toBeTrue()
        ->and(fn () => resolve(EnablePointInTimeRecovery::class)->handle($nine, new RecoverySettings($storage->id, new RecoverySchedule(7, 3))))
        ->toThrow(ValidationException::class, 'Point-in-time recovery is not available for MongoDB 9.0 at this moment.');
});

it('moves the primary to the target only once the old primary stepped down', function (string $stepDown, array $expected, array $absent) {
    $database = idleDatabase('mongodb', 3);
    $driver = $database->provider();
    [$seed, $standby, $other] = $database->nodes()->members()->get()->all();
    $target = $driver->clientAddress($standby);
    $script = view(Database::providerFor('mongodb')->scriptView('cluster.failover'), [...$driver->scriptData()->for(), 'target' => $target])->render();
    preg_match("/<<'JS'[^\n]*\n(.*?)\nJS\n/s", $script, $block);
    $members = collect([$seed, $standby, $other])->map(fn ($node): array => ['host' => $driver->clientAddress($node), 'priority' => 1])->all();

    $output = simulatedMongosh($block[1], 'const members = '.json_encode($members).'; const target0 = '.json_encode($target).';
        const calls = []; let unfrozen = false, primary = members[0].host;
        globalThis.connect = (uri) => { const host = uri.split("@")[1].split("/")[0]; return { adminCommand: (cmd) => { calls.push(`${host} ${Object.keys(cmd)[0]} ${Object.values(cmd)[0]}`); if (host === target0 && cmd.replSetFreeze === 0) { unfrozen = true; } if (host === target0 && cmd.replSetStepUp && unfrozen) { primary = host; } return { ok: 1 }; }, hello: () => ({ isWritablePrimary: primary === host }) }; };
        globalThis.db = { hello: () => ({ me: members[0].host, isWritablePrimary: primary === members[0].host }), adminCommand: (cmd) => { calls.push(`self ${Object.keys(cmd)[0]}`); '.$stepDown.' return { ok: 1 }; } };
        globalThis.rs = { conf: () => ({ members }) };
        process.on("exit", () => console.log(calls.join("\n")));');
    $placeholders = fn (array $lines): array => array_map(fn (string $line): string => str_replace('{target}', $target, $line), $lines);

    expect($output)->toContain(...$placeholders($expected))
        ->and(collect($placeholders($absent))->filter(fn (string $line): bool => str_contains($output, $line))->all())->toBe([]);
})->with([
    'a target still in its stepdown period' => ['primary = null;', ["{target} replSetFreeze 0\nself replSetStepDown", '{target} replSetStepUp 1', '{target} is the primary now'], []],
    'a healthy handoff' => ['primary = target0;', ['{target} is the primary now'], ['replSetStepUp']],
    'a refused step-down' => ['throw Object.assign(new Error("No electable secondaries caught up as of now"), { codeName: "ExceededTimeLimit" });', ['kept the primary: No electable secondaries caught up as of now'], ['replSetStepUp', 'is the primary now']],
]);

it('counts healthy voters without members that were just added and still sync', function (array $members, int $votingMembersCount, bool $forced) {
    $database = idleDatabase('mongodb', 3);
    $driver = $database->provider();
    $seed = $database->seed();
    $script = view(Database::providerFor('mongodb')->scriptView('cluster.force-quorum'), [...$driver->scriptData()->for($seed), 'instance' => $driver->clientAddress($seed), 'othersAreDown' => false])->render();
    preg_match("/<<'JS'[^\n]*\n(.*?)\nJS\n/s", $script, $block);

    $output = simulatedMongosh($block[1], 'const members = '.json_encode($members).'; let forced = false;
        Date.now = ((start) => () => (start += 5000))(0);
        globalThis.rs = {
            status: () => ({ votingMembersCount: '.$votingMembersCount.', members }),
            conf: () => ({ members: members.map(({ name }) => ({ host: name, votes: 1, priority: 1 })) }),
            reconfig: (config, options) => { forced = options.force === true; },
        };
        process.on("exit", () => console.log(`forced=${forced}`));');

    expect($output)->toContain('forced='.($forced ? 'true' : 'false'));
})->with([
    'a newly added member in initial sync has no vote yet' => [[['name' => 'a:25060', 'health' => 1, 'stateStr' => 'SECONDARY'], ['name' => 'b:25060', 'health' => 0, 'stateStr' => '(not reachable/healthy)'], ['name' => 'c:25060', 'health' => 1, 'stateStr' => 'STARTUP2']], 2, true],
    'an existing voter that resyncs still votes' => [[['name' => 'a:25060', 'health' => 1, 'stateStr' => 'SECONDARY'], ['name' => 'b:25060', 'health' => 0, 'stateStr' => '(not reachable/healthy)'], ['name' => 'c:25060', 'health' => 1, 'stateStr' => 'STARTUP2']], 3, false],
]);

it('reports the replica set quorum from the healthy voters', function () {
    $database = idleDatabase('mongodb', 3);
    $script = view(Database::providerFor('mongodb')->scriptView('cluster.quorum'), $database->provider()->scriptData()->for($database->seed()))->render();
    preg_match("/<<'JS'[^\n]*\n(.*?)\nJS\n/s", $script, $block);

    $output = simulatedMongosh($block[1], 'globalThis.rs = {
            status: () => ({ votingMembersCount: 2, members: [{ name: "a:25060", health: 1, stateStr: "PRIMARY" }, { name: "b:25060", health: 1, stateStr: "SECONDARY" }, { name: "c:25060", health: 1, stateStr: "STARTUP2" }] }),
            conf: () => ({ members: ["a", "b", "c"].map((host) => ({ host: `${host}:25060`, votes: 1 })) }),
        };');

    expect($output)->toBe("online=2\ntotal=2\n")
        ->and(bashErrorsIn($script))->toBe('');
});

function simulatedMongosh(string $block, string $stubs): string
{
    $preamble = 'var require = () => ({ readFileSync: () => "secret" }); globalThis.print = (...values) => console.log(...values); globalThis.sleep = () => {};';
    $result = Process::input($preamble.$stubs."\n".$block)->run(['node']);

    return $result->output().$result->errorOutput();
}
