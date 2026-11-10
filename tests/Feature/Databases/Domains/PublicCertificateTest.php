<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\InstallNodeCertificate;
use App\Actions\Databases\Nodes\DeployNode;
use App\Enums\Databases\DatabaseMode;
use App\Models\Database;
use App\Models\DatabaseNode;
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
