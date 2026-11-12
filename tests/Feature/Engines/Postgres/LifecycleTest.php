<?php

declare(strict_types=1);

use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Secrets;
use App\Engines\EngineClient;
use App\Engines\Requests\ClusterEnable;
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
    config(['databasify.php_engines' => ['postgres']]);
    $this->scripts = fakeScripts();
    $this->node = engineNode();
});

function selfSignedPair(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'node'], $key), null, $key, 1);
    openssl_x509_export($certificate, $pem);
    openssl_pkey_export($key, $keyPem);

    return [$pem, $keyPem];
}

it('starts, wakes and restarts the node through its units', function () {
    $client = resolve(EngineClient::class);
    $client->send($this->node, new NodeStart);
    $client->send($this->node, new NodeWake(all: true));
    $client->send($this->node, new NodeRestart);

    $this->scripts->assertRan('postgres/nodes/start', fn (string $script): bool => str_contains($script, 'unit_do enable "$name"') && str_contains($script, 'run node configure first'))
        ->assertRan('postgres/nodes/wake', fn (string $script, array $env): bool => $env === ['ALL' => 'true'] && str_contains($script, 'unit_do start databasify-etcd'))
        ->assertRan('postgres/nodes/restart', fn (string $script): bool => str_contains($script, 'databasify-patroni'));
});

it('bootstraps the service roles and the default account', function () {
    resolve(EngineClient::class)->send($this->node, new NodeBootstrap(new Secrets('adm', 'rep', 'agent'), new DefaultAccount('defaultdb', 'databasify', 'pw')));

    $this->scripts->assertRan('postgres/nodes/bootstrap', fn (string $script, array $env): bool => $env['ADMIN_PASSWORD'] === 'adm'
        && $env['POOLER_PASSWORD'] === hash_hmac('sha256', 'pgbouncer', 'agent')
        && $env['ACCOUNT_DATABASE'] === 'defaultdb' && $env['ACCOUNT_USER'] === 'databasify'
        && str_contains($script, 'CREATE OR REPLACE FUNCTION pgbouncer.get_auth')
        && ! str_contains($script, 'adm'));
});

it('refuses to bootstrap without every secret', function () {
    expect(fn () => resolve(EngineClient::class)->send($this->node, new NodeBootstrap(new Secrets('adm', null, 'agent'), null)))
        ->toThrow(InvalidEngineRequest::class, 'PostgreSQL needs the admin, replication and agent secrets');
});

it('installs a matching certificate and refuses a mismatched one', function () {
    [$certificate, $key] = selfSignedPair();
    [, $otherKey] = selfSignedPair();

    resolve(EngineClient::class)->send($this->node, new TlsInstall($certificate, $key, $certificate));

    $this->scripts->assertRan('postgres/nodes/tls-install', fn (string $script, array $env): bool => $env['KEY'] === $key && str_contains($script, 'write_file /srv/postgres/tls/server.key 600'));
    expect(fn () => resolve(EngineClient::class)->send($this->node, new TlsInstall($certificate, $otherKey, $certificate)))
        ->toThrow(InvalidEngineRequest::class, 'the certificate and key are not a matching PEM pair')
        ->and(fn () => resolve(EngineClient::class)->send($this->node, new TlsInstall($certificate, $key, 'not pem')))
        ->toThrow(InvalidEngineRequest::class, 'the CA is not PEM');
});

it('waits for readiness and enables the cluster as tasks under their keys', function () {
    $ready = resolve(EngineClient::class)->send($this->node, new NodeReady('flow.3.1', expectPrimary: true));
    $enable = resolve(EngineClient::class)->send($this->node, new ClusterEnable('flow.4.1'));

    expect($ready->key)->toBe('flow.3.1')->and($enable->key)->toBe('flow.4.1');
    $this->scripts->assertRan('postgres/nodes/ready', fn (string $script, array $env): bool => $env === ['EXPECT_PRIMARY' => 'true'] && str_contains($script, "phase 'waiting for PostgreSQL'") && str_contains($script, 'PostgreSQL was not ready in time'))
        ->assertRan('postgres/cluster/enable', fn (string $script): bool => str_contains($script, "phase 'starting Patroni'") && str_contains($script, 'Patroni did not lead or follow the cluster in time'));
});

it('does not reset PostgreSQL nodes', function () {
    expect(fn () => resolve(EngineClient::class)->send($this->node, new NodeReset(0, [])))->toThrow(EngineUnsupported::class, 'this engine does not implement "node reset"');
});
