<?php

declare(strict_types=1);

use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    $this->scripts = fakeScripts()->respond('engines.mongodb.nodes.configure', ['changed' => true]);
    $this->node = idleDatabase('mongodb', 1, versionLine: '8.0')->nodes()->sole();
});

function mongoFiles(array $env): array
{
    return collect(explode("\n", $env['FILES']))->mapWithKeys(function (string $line): array {
        [$path, $mode, $content] = explode("\t", $line);

        return [$path => ['mode' => $mode, 'content' => base64_decode($content)]];
    })->all();
}

it('configures with the Go-identical files', function (string $case) {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $fixture = goFixture("configure-{$case}.json", 'mongodb');

    $this->node->driver()->nodes()->configure(nodeConfigFromFixture($fixture['request']));

    $this->scripts->assertRan('engines.mongodb.nodes.configure', function (string $script, array $env) use ($fixture): bool {
        $files = mongoFiles($env);

        return $files['/srv/mongodb/conf/mongod.conf'] === ['mode' => '600', 'content' => $fixture['mongodConf']]
            && $files['/srv/mongodb/conf/keyfile'] === ['mode' => '400', 'content' => $fixture['keyfile']]
            && $files['/srv/mongodb/conf/admin.pw'] === ['mode' => '600', 'content' => $fixture['request']['secrets']['admin']]
            && base64_decode($env['MANIFEST']) === $fixture['manifest']
            && $env['TUNED_JSON'] !== ''
            && str_contains($script, 'unit_do daemon-reload')
            && str_contains($script, 'unit_do start pbm-agent');
    });
})->with(['standalone', 'archived', 'readonly']);

it('refuses what Go refuses before touching the node', function (Closure $change, string $message) {
    $request = goFixture('configure-standalone.json', 'mongodb')['request'];

    expect(fn () => $this->node->driver()->nodes()->configure(nodeConfigFromFixture($change($request))))->toThrow(InvalidEngineRequest::class, $message);
    expect($this->scripts->ran())->toBe([]);
})->with([
    'secrets' => [fn (array $request): array => [...$request, 'secrets' => [...$request['secrets'], 'replication' => null]], 'MongoDB needs the admin and replication secrets'],
    'members' => [fn (array $request): array => [...$request, 'members' => array_fill(0, 51, $request['members'][0])], 'a MongoDB replica set has at most 50 members; got 51'],
    'setting' => [fn (array $request): array => [...$request, 'settings' => ['journal' => 'off']], 'setting "journal" is not offered'],
]);

it('applies the runtime settings like Go', function () {
    $fixture = goFixture('configure-standalone.json', 'mongodb');
    $ssh = respondToFileGet(fakeSsh(), 'state/node.json', json_encode($fixture['request']));
    respondToFileGet($ssh, 'state/tuned.json', '{"cacheSizeGB":"1.50","maxIncomingConnections":"2048"}');

    $this->node->driver()->config()->apply();

    $this->scripts->assertRan('engines.mongodb.config.apply', fn (string $script, array $env): bool => $env['VERBOSITY'] === '2' && $env['SLOW'] === '250' && $env['LIFETIME'] === '60' && $env['CACHE_MB'] === '1536'
        && $env['READ_CONCERN'] === 'local' && $env['WRITE_CONCERN'] === 'majority'
        && str_contains($script, "wiredTigerEngineRuntimeConfig: 'cache_size=' + env.CACHE_MB + 'M'")
        && str_contains($script, 'setDefaultRWConcern'));
});

it('lists the restart settings whose live value differs', function () {
    $fixture = goFixture('configure-standalone.json', 'mongodb');
    $ssh = respondToFileGet(fakeSsh(), 'state/node.json', json_encode($fixture['request']));
    respondToFileGet($ssh, 'state/tuned.json', '{"cacheSizeGB":"1.50","maxIncomingConnections":"2048"}');
    $this->scripts->respond('engines.mongodb.config.show', ['values' => ['maxIncomingConnections' => '1000000', 'cacheSizeGB' => '0.25', 'verbosity' => '0']]);

    expect($this->node->driver()->config()->pendingRestart())->toBe(['maxIncomingConnections']);
});

it('syncs nothing and needs a configured node to apply or tune', function () {
    respondToFileGet(fakeSsh(), 'state/node.json', null);
    $this->node->driver()->config()->sync();

    expect(fn () => $this->node->driver()->config()->apply())->toThrow(EngineBusy::class, 'run node configure first')
        ->and(fn () => $this->node->driver()->config()->tune())->toThrow(EngineBusy::class, 'run node configure first')
        ->and($this->node->driver()->config()->fields())->toHaveCount(7);
});
