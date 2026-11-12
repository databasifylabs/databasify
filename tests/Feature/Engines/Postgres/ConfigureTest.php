<?php

declare(strict_types=1);

use App\Engines\EngineClient;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    config(['databasify.php_engines' => ['postgres']]);
    $this->scripts = fakeScripts()->respond('postgres/nodes/configure', ['changed' => true]);
    $this->node = engineNode();
    $this->node->database->update(['version_line' => '18']);
});

it('configures with the saved tuning and the Go-identical files', function () {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => base64_encode('{"work_mem":"8MB","shared_buffers":"1024MB"}'), 'exists' => true]);
    $request = configureFromFixture(goFixture('configure-standalone.json')['request']);

    $reply = resolve(EngineClient::class)->send($this->node->fresh(), $request);

    expect($reply->changed)->toBeTrue();
    $this->scripts->assertRan('postgres/nodes/configure', function (string $script, array $env): bool {
        $files = collect(json_decode($env['FILES'], true))->keyBy('path');
        $serverConf = base64_decode($files['/srv/postgres/conf/postgresql.conf']['content']);

        return $env['TUNED_JSON'] === ''
            && $files['/srv/postgres/conf/postgresql.conf']['mode'] === '640'
            && str_contains($serverConf, "work_mem = '8MB'\n")
            && str_contains($serverConf, "shared_buffers = '1024MB'\n")
            && str_contains($serverConf, "max_connections = '200'\n")
            && str_contains(base64_decode($files['/srv/postgres/conf/pgbouncer.ini']['content']), 'default_pool_size = __DEFAULT_POOL_SIZE__')
            && $env['CLUSTERED'] === 'false' && $env['LINE'] === '18'
            && json_decode($env['REQUEST_JSON'], true)['settings'] === ['max_connections' => '200', 'timezone' => 'Asia/Dhaka', 'work_mem' => '8MB']
            && str_contains($script, '/bin/initdb')
            && str_contains($script, 'sed "s/__DEFAULT_POOL_SIZE__/$pool_size/"')
            && str_contains($script, 'databasify service reload');
    });
});

it('tunes from the node itself when nothing is saved yet', function () {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $request = configureFromFixture(goFixture('configure-primary.json')['request']);

    resolve(EngineClient::class)->send($this->node->fresh(), $request);

    $this->scripts->assertRan('postgres/nodes/configure', fn (string $script, array $env): bool => json_decode($env['TUNED_JSON'], true)['shared_buffers'] === '1024MB'
        && $env['CLUSTERED'] === 'true' && $env['ARCHIVE'] === 'true' && $env['IP'] === '10.0.0.2');
});

it('refuses a setting the catalog does not accept before touching the node', function () {
    $request = goFixture('configure-standalone.json')['request'];
    $request['settings'] = ['fsync' => 'off'];

    expect(fn () => resolve(EngineClient::class)->send($this->node->fresh(), configureFromFixture($request)))
        ->toThrow(InvalidEngineRequest::class, 'setting "fsync" is not offered');
    expect($this->scripts->ran())->toBe([]);
});
