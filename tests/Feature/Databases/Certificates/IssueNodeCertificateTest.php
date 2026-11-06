<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\IssueNodeCertificate;
use App\Enums\Databases\NodeRole;

it('issues a read-only node its own certificate from the database authority', function () {
    $database = withZone(idleDatabase('mysql', 2));
    markRoles($database, NodeRole::Replica, NodeRole::Primary);
    $primary = $database->nodes()->get()[1];
    $reader = withReadOnlyNode($database, ['name' => 'reports']);
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'authority-cert', 'ca.key' => 'authority-key'])->withNodeCertificates($reader->id, ['stale.crt' => 'old'])]);
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'ca.key', 'server.crt', 'server.key'));

    resolve(IssueNodeCertificate::class)->handle($database->fresh(), $reader->fresh());

    $settings = $database->fresh()->settings;
    expect($ssh->runs[0]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(0))->toContain("'DNS:reports.db.example.com,DNS:private-reports.db.example.com,IP:{$reader->server->public_ip},IP:{$reader->privateIp()}'")->toContain('rsa_keygen_bits:2048')
        ->toContain("echo '".base64_encode('authority-key')."' | base64 -d > \"\$work/ca.key\"")
        ->and($settings->certificates)->toBe([
            'ca.crt' => 'authority-cert',
            'ca.key' => 'authority-key',
            "node-{$reader->id}/server.crt" => 'server.crt-content',
            "node-{$reader->id}/server.key" => 'server.key-content',
        ]);
});
