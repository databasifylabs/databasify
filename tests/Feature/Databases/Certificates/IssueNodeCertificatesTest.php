<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\IssueNodeCertificates;
use App\DTOs\Databases\IssuedCertificate;
use App\Enums\Databases\DatabaseMode;
use App\Models\DatabaseNode;

it('issues a CA once and a certificate per node with its private and public addresses', function () {
    $ssh = fakeSsh();
    $database = idleDatabase('postgres', 2);
    $before = count($ssh->runs);

    resolve(IssueNodeCertificates::class)->handle($database);
    $database->refresh();
    $authority = $database->settings->certificate('ca.crt');

    resolve(IssueNodeCertificates::class)->handle($database);
    $database->refresh();

    expect($ssh->runs)->toHaveCount($before)
        ->and($database->settings->certificate('ca.crt'))->toBe($authority)
        ->and(openssl_x509_parse($authority)['subject']['CN'])->toBe("{$database->name} CA");

    $database->nodes->each(function (DatabaseNode $node) use ($database, $authority): void {
        $certificate = (string) $database->settings->nodeCertificate($node->id, 'server.crt');

        expect(IssuedCertificate::fromPem($certificate)?->isCurrentFor([$node->server->address(), $node->privateIp()]))->toBeTrue()
            ->and(openssl_x509_verify($certificate, openssl_pkey_get_public($authority)))->toBe(1)
            ->and(openssl_x509_check_private_key($certificate, (string) $database->settings->nodeCertificate($node->id, 'server.key')))->toBeTrue();
    });
});

it('reissues a node certificate that still names a domain the database left', function () {
    fakeSsh();
    $database = withCurrentCertificates(withZone(idleDatabase('postgres', 1, DatabaseMode::Single, 'app-db')));
    $node = $database->nodes()->sole();
    $database->moveToZone(null);

    expect($database->refresh()->allCertificatesCurrent())->toBeFalse();

    resolve(IssueNodeCertificates::class)->handle($database);
    $names = IssuedCertificate::fromPem((string) $database->refresh()->settings->nodeCertificate($node->id, 'server.crt'))?->names;

    expect($names)->not->toContain('app-db.db.example.com')
        ->and($database->allCertificatesCurrent())->toBeTrue();
});
