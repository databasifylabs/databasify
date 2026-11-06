<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\IssueCertificates;
use App\Enums\Databases\DatabaseMode;

it('serves the zone wildcard on every valkey node and offers no ca file', function () {
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    withZoneCertificate($database->dnsZone);
    $reader = withReadOnlyNode($database);
    fakeSsh()->respond(certificateOutput('ca.crt', 'ca.key', 'server.pem'));

    resolve(IssueCertificates::class)->handle($database->fresh());

    $database = $database->fresh();
    $bundle = $database->dnsZone->certificateBundle();
    expect($database->settings->certificate('server.pem'))->toBe($bundle)
        ->and($database->certificateFilesFor($reader))->toBe(['ca.crt' => 'ca.crt-content', 'server.pem' => $bundle])
        ->and($database->allCertificatesCurrent())->toBeTrue()
        ->and($database->clientCaCertificate())->toBeNull()
        ->and($database->provider()->connection()->command)->toBe('valkey-cli --tls -u rediss://{username}:{password}@{host}:{port}')
        ->and($database->provider()->connection()->envNote)->toBeNull();
});

it('asks for a reload once the zone certificate is renewed', function () {
    $database = withZone(idleDatabase('redis', 1, DatabaseMode::Single, 'cache'));
    withZoneCertificate($database->dnsZone);
    fakeSsh()->respond(certificateOutput('ca.crt', 'ca.key', 'server.pem'));
    resolve(IssueCertificates::class)->handle($database->fresh());

    withZoneCertificate($database->dnsZone);

    expect($database->fresh()?->allCertificatesCurrent())->toBeFalse();
});

it('issues one zone certificate for every key-value database on the zone', function () {
    $valkey = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    $redis = idleDatabase('redis', 1, DatabaseMode::Single, 'queue');
    $redis->update(['dns_zone_id' => $valkey->dns_zone_id]);
    fakeCloudflare([]);
    $authority = fakeCertificateAuthority();
    fakeSsh()->respond(certificateOutput('ca.crt', 'ca.key', 'server.pem'))->respond(certificateOutput('ca.crt', 'ca.key', 'server.pem'));

    resolve(IssueCertificates::class)->handle($valkey->fresh());
    resolve(IssueCertificates::class)->handle($redis->fresh());

    expect($authority->issued)->toBe(['*.db.example.com'])
        ->and($redis->fresh()->settings->certificate('server.pem'))->toBe($valkey->fresh()->settings->certificate('server.pem'));
});

it('keeps sql engines on their own certificate authority with a domain', function (string $engine) {
    $database = withZone(idleDatabase($engine, 1, DatabaseMode::Single, 'app'));
    $authority = fakeCertificateAuthority();
    fakeSsh()->respond(certificateOutput('ca.crt', 'ca.key', 'server.crt', 'server.key'));

    resolve(IssueCertificates::class)->handle($database->fresh());

    expect($authority->issued)->toBe([])
        ->and($database->fresh()->clientCaCertificate())->toBe('ca.crt-content');
})->with(['mysql', 'mariadb', 'postgres']);
