<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\EnsureNodeCertificates;
use App\Enums\Databases\DatabaseMode;

it('re-issues read-only certificates that are missing, incomplete, expiring or missing a hostname', function () {
    $database = withZone(idleDatabase('postgres', 1, DatabaseMode::Single));
    $current = withReadOnlyNode($database, ['name' => 'current']);
    $partial = withReadOnlyNode($database, ['name' => 'partial']);
    $expiring = withReadOnlyNode($database, ['name' => 'expiring']);
    $renamed = withReadOnlyNode($database, ['name' => 'renamed']);
    $missing = withReadOnlyNode($database, ['name' => 'missing']);
    $settings = $database->settings
        ->withNodeCertificates($current->id, ['server.crt' => issuedPem($current->certificateNames()), 'server.key' => 'key'])
        ->withNodeCertificates($partial->id, ['server.crt' => issuedPem($partial->certificateNames())])
        ->withNodeCertificates($expiring->id, ['server.crt' => issuedPem($expiring->certificateNames(), days: 10), 'server.key' => 'key'])
        ->withNodeCertificates($renamed->id, ['server.crt' => issuedPem([$renamed->server->public_ip, $renamed->privateIp()]), 'server.key' => 'key']);
    $database->update(['settings' => $settings]);
    $ssh = fakeSsh();
    foreach (range(1, 4) as $ignored) {
        $ssh->respond(certificateOutput('server.crt', 'server.key'));
    }

    resolve(EnsureNodeCertificates::class)->handle($database->fresh());

    expect($ssh->runs)->toHaveCount(4)
        ->and(collect($ssh->runs)->pluck('script')->implode(''))->toContain('partial.db.example.com')->toContain('expiring.db.example.com')->toContain('renamed.db.example.com')->toContain('missing.db.example.com')->not->toContain(' current.db.example.com')
        ->and($database->fresh()->settings->nodeCertificate($renamed->id, 'server.crt'))->toBe('server.crt-content');
});

it('keeps a sentinel read-only node whose pem is current', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database);
    $database->update(['settings' => $database->settings->withNodeCertificates($reader->id, ['server.pem' => issuedPem($reader->certificateNames())])]);
    $ssh = fakeSsh();

    resolve(EnsureNodeCertificates::class)->handle($database->fresh());

    expect($ssh->runs)->toBe([]);
});

it('does nothing for databases without read-only nodes', function () {
    $database = idleDatabase('valkey', 3);
    $ssh = fakeSsh();

    resolve(EnsureNodeCertificates::class)->handle($database);

    expect($ssh->runs)->toBe([]);
});
