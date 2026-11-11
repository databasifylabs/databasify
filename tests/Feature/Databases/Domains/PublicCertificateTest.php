<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\InstallNodeCertificate;
use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Dns\EnsureZoneCertificate;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RenewCertificatesJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeServerConnection;

function installedCertificates(FakeServerConnection $ssh, int $from): array
{
    return collect($ssh->runs)->slice($from)->keys()
        ->map(fn (int $index): string => $ssh->engineRequestAt($index))
        ->filter(fn (string $request): bool => str_contains($request, '"certificate"'))
        ->map(fn (string $request): string => json_decode($request, true)['certificate'])
        ->values()->all();
}

function installEveryNodeCertificate(Database $database): void
{
    $database->nodesWithServers()->each(fn (DatabaseNode $node) => resolve(InstallNodeCertificate::class)->handle($database, $node));
}

it('issues the zone certificate before installing it on a node of a domain it has no certificate for yet', function () {
    $ssh = fakeEngine();
    fakeCloudflare([['id' => 'txt-1', 'content' => '"challenge-token"', 'comment' => 'databasify']]);
    $authority = fakeCertificateAuthority();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    $before = count($ssh->runs);

    resolve(DeployNode::class)->handle($database, $database->nodes()->sole());

    expect($authority->issued)->toBe(['*.db.example.com'])
        ->and(installedCertificates($ssh, $before))->toBe([$database->dnsZone->fresh()->certificate]);
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
    $other->update(['dns_zone_id' => $database->dns_zone_id, 'status' => DatabaseStatus::Ready]);
    Queue::fake([RenewCertificatesJob::class]);

    resolve(EnsureZoneCertificate::class)->handle($database->dnsZone);

    Queue::assertPushed(RenewCertificatesJob::class, fn (RenewCertificatesJob $job): bool => $job->database->is($other));
});
