<?php

declare(strict_types=1);

use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Secrets;
use App\Engines\EngineClient;
use App\Engines\Requests\NodeBootstrap;
use App\Engines\Requests\NodeReady;
use App\Engines\Requests\NodeReset;
use App\Engines\Requests\NodeRestart;
use App\Engines\Requests\NodeStart;
use App\Engines\Requests\NodeWake;
use App\Engines\Requests\TlsInstall;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    config(['databasify.php_engines' => ['mongodb']]);
    $this->scripts = fakeScripts();
    $this->node = idleDatabase('mongodb', 1)->nodes()->sole();
});

it('starts, wakes and restarts like Go', function () {
    $client = resolve(EngineClient::class);
    $client->send($this->node, new NodeStart);
    $client->send($this->node, new NodeWake(all: false));
    $client->send($this->node, new NodeRestart);

    $this->scripts->assertRan('mongodb/nodes/start', fn (string $script): bool => str_contains($script, 'unit_do start mongod') && str_contains($script, 'unit_do start pbm-agent'))
        ->assertRan('mongodb/nodes/wake', fn (string $script): bool => str_contains($script, 'kill -s KILL mongod') && str_contains($script, 'unit_do stop mongod'))
        ->assertRan('mongodb/nodes/restart', fn (string $script): bool => str_contains($script, 'unit_do restart mongod'));
    expect(fn () => $client->send($this->node, new NodeReset(0, [])))->toThrow(EngineUnsupported::class, 'this engine does not implement "node reset"');
});

it('bootstraps the seed: initiate, root, agent and the default account', function () {
    respondToFileGet(fakeSsh(), 'state/node.json', json_encode(goFixture('configure-standalone.json', 'mongodb')['request']));
    $this->scripts->respond('mongodb/accounts/create-user', ['created' => true]);

    resolve(EngineClient::class)->send($this->node, new NodeBootstrap(new Secrets("ad'm", 'rep', 'agent'), new DefaultAccount('defaultdb', 'databasify', 'pw')));

    $this->scripts->assertRan('mongodb/nodes/bootstrap', fn (string $script, array $env): bool => $env['HOST'] === '10.0.0.1:25060' && $env['ADMIN_PASSWORD'] === "ad'm"
        && str_contains($script, 'replSetInitiate') && str_contains($script, 'the server was not ready within 2m0s')
        && str_contains($script, "createRole: 'pbmAnyAction'") && str_contains($script, "createUser: 'databasify_pbm'"))
        ->assertRan('mongodb/accounts/create-database', fn (string $script, array $env): bool => $env['NAME'] === 'defaultdb');
});

it('skips bootstrap on a node that is not the seed', function () {
    $request = goFixture('configure-readonly.json', 'mongodb')['request'];
    respondToFileGet(fakeSsh(), 'state/node.json', json_encode($request));

    resolve(EngineClient::class)->send($this->node, new NodeBootstrap(new Secrets('adm', 'rep', 'agent'), new DefaultAccount('defaultdb', 'databasify', 'pw')));

    expect($this->scripts->ran())->toBe([]);
});

it('refuses to bootstrap without the admin and agent secrets', function () {
    expect(fn () => resolve(EngineClient::class)->send($this->node, new NodeBootstrap(new Secrets('adm', 'rep', null), null)))
        ->toThrow(InvalidEngineRequest::class, 'MongoDB needs the admin and agent secrets');
});

it('installs a combined TLS pem and rotates certificates', function () {
    [$certificate, $key] = selfSignedPair();

    resolve(EngineClient::class)->send($this->node, new TlsInstall($certificate, $key, $certificate));

    $this->scripts->assertRan('mongodb/nodes/tls-install', fn (string $script, array $env): bool => $env['PEM'] === rtrim($certificate, "\n")."\n".$key && str_contains($script, 'rotateCertificates'));
});

it('waits for MongoDB as a task', function () {
    resolve(EngineClient::class)->send($this->node, new NodeReady('ready.1', true));

    $this->scripts->assertRan('mongodb/nodes/ready', fn (string $script): bool => str_contains($script, "phase 'waiting for MongoDB'") && str_contains($script, 'MongoDB keeps stopping while it starts, see its log'));
});
