<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\InstallNodeCertificate;
use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Dns\EnsureZoneCertificate;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\RenewCertificatesJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\DnsZone;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function installedCertificates(): array
{
    return array_map(fn (array $call): string => $call['args'][0], recordedArgs('nodes.installTls'));
}

function withUnreadableZoneKey(DnsZone $zone): DnsZone
{
    withZoneCertificate($zone);
    DB::table('dns_zones')->where('id', $zone->id)->update(['certificate_key' => new Encrypter(random_bytes(32), 'aes-256-cbc')->encryptString('old-key')]);

    return $zone->fresh() ?? $zone;
}

function installEveryNodeCertificate(Database $database): void
{
    $database->nodesWithServers()->each(fn (DatabaseNode $node) => resolve(InstallNodeCertificate::class)->handle($database, $node));
}

it('issues the zone certificate before installing it on a node of a domain it has no certificate for yet', function () {
    fakeEngine();
    fakeCloudflare([['id' => 'txt-1', 'content' => '"challenge-token"', 'comment' => 'databasify']]);
    $authority = fakeCertificateAuthority();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));

    resolve(DeployNode::class)->handle($database, $database->nodes()->sole());

    expect($authority->issued)->toBe(['*.db.example.com'])
        ->and(installedCertificates())->toBe([$database->dnsZone->fresh()->certificate]);
});

it('keeps the private connection details until every node serves the zone certificate', function () {
    fakeEngine();
    $database = withCurrentCertificates(withZone(idleDatabase('valkey', 2, DatabaseMode::Single, 'cache')));
    installEveryNodeCertificate($database);
    withZoneCertificate($database->dnsZone);
    $engine = $database->engine();

    resolve(InstallNodeCertificate::class)->handle($database->refresh(), $database->nodes()->firstOrFail());

    expect($database->clientCaCertificate())->toBe($database->settings->certificate('ca.crt'))
        ->and($database->connectionProfile())->toEqual($engine->connection);

    installEveryNodeCertificate($database);

    expect($database->clientCaCertificate())->toBeNull()
        ->and($database->connectionProfile())->toEqual($engine->publicConnection)->not->toEqual($engine->connection);
});

it('renews the certificates while a node still serves an older zone certificate', function () {
    fakeEngine();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    withZoneCertificate($database->dnsZone);
    installEveryNodeCertificate($database->refresh());

    expect($database->allCertificatesCurrent())->toBeTrue();

    withZoneCertificate($database->dnsZone);

    expect($database->refresh()->allCertificatesCurrent())->toBeFalse();
});

it('keeps the public connection details while a joining node has no certificate yet', function () {
    fakeEngine();
    $database = withZone(idleDatabase('valkey', 2, DatabaseMode::Single, 'cache'));
    withZoneCertificate($database->dnsZone);
    installEveryNodeCertificate($database->refresh());
    $database->nodes()->firstOrFail()->update(['certificate_fingerprint' => null]);

    expect($database->servesPublicCertificate())->toBeTrue();
});

it('falls back to the private connection details once the zone certificate is no longer current', function () {
    fakeEngine();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    withZoneCertificate($database->dnsZone, days: 10);
    installEveryNodeCertificate($database->refresh());

    expect($database->servesPublicCertificate())->toBeFalse()
        ->and($database->clientCaCertificate())->toBe($database->settings->certificate('ca.crt'));
});

it('queues a certificate renewal for the other databases on a zone once it gets a new certificate', function () {
    fakeCloudflare([['id' => 'txt-1', 'content' => '"challenge-token"', 'comment' => 'databasify']]);
    fakeCertificateAuthority();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    $other = idleDatabase('redis', 1, DatabaseMode::Single, 'sessions');
    $other->update(['dns_zone_id' => $database->dns_zone_id]);
    markRoles($other, NodeRole::Primary);
    Queue::fake([RenewCertificatesJob::class]);

    resolve(EnsureZoneCertificate::class)->handle($database->dnsZone);

    Queue::assertPushed(RenewCertificatesJob::class, fn (RenewCertificatesJob $job): bool => $job->database->is($other));
});

it('installs the private certificate when the zone certificate key can no longer be read', function () {
    fakeEngine();
    $database = withCurrentCertificates(withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache')));
    withUnreadableZoneKey($database->dnsZone);

    installEveryNodeCertificate($database->refresh());

    expect(installedCertificates())->toBe([$database->settings->nodeCertificate($database->nodes()->sole()->id, 'server.crt')])
        ->and($database->clientCaCertificate())->toBe($database->settings->certificate('ca.crt'));
});

it('issues a new zone certificate when the stored key can no longer be read', function () {
    fakeCloudflare([['id' => 'txt-1', 'content' => '"challenge-token"', 'comment' => 'databasify']]);
    $authority = fakeCertificateAuthority();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    $zone = withUnreadableZoneKey($database->dnsZone);

    resolve(EnsureZoneCertificate::class)->handle($zone);

    expect($authority->issued)->toBe(['*.db.example.com'])
        ->and($zone->fresh()->hasCurrentCertificate())->toBeTrue();
});
