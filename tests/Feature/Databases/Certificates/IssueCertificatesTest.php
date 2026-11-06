<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\IssueCertificates;
use App\Enums\Databases\NodeRole;
use App\Models\ServerLog;

it('issues certificates for every member name on the primary and keeps the node certificates', function () {
    $database = withZone(idleDatabase('valkey', 2, name: 'db'));
    $bundle = withZoneCertificate($database->dnsZone)->certificateBundle();
    withReadOnlyNode($database);
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'old-ca', 'node-9/server.pem' => 'reader'])]);
    markRoles($database, NodeRole::Replica, NodeRole::Primary);
    [$first, $second] = $database->nodes()->members()->with('server')->get()->all();
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'ca.key', 'server.pem'));

    resolve(IssueCertificates::class)->handle($database->fresh());

    $names = implode(',', ['DNS:db.db.example.com', 'DNS:private-db.db.example.com', 'DNS:replica-db.db.example.com', 'DNS:private-replica-db.db.example.com', "IP:{$first->server->public_ip}", "IP:{$first->privateIp()}", "IP:{$second->server->public_ip}", "IP:{$second->privateIp()}"]);
    expect($ssh->runs)->toHaveCount(1)
        ->and($ssh->runs[0]['server'])->toBe($second->server->name)
        ->and($ssh->scriptAt(0))->toContain("subjectAltName=%s\\nextendedKeyUsage=serverAuth,clientAuth\\nkeyUsage=digitalSignature\\n' '{$names}'")->toContain("-subj '/CN=Valkey CA'")->toContain('cat server.crt server.key > server.pem')->not->toContain('base64 -d > "$work/ca.crt"')
        ->and($database->fresh()->settings->certificates)->toBe(['ca.crt' => 'ca.crt-content', 'node-9/server.pem' => 'reader', 'ca.key' => 'ca.key-content', 'server.pem' => $bundle])
        ->and(ServerLog::query()->pluck('output')->implode(''))->not->toContain('CERT ');
});

it('signs with the existing authority when asked to reuse it', function () {
    $database = idleDatabase('postgres', 1);
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'authority-cert', 'ca.key' => 'authority-key'])]);
    $ssh = fakeSsh()->respond(certificateOutput('server.crt', 'server.key'));

    resolve(IssueCertificates::class)->handle($database->fresh(), reuseAuthority: true);

    expect($ssh->scriptAt(0))
        ->toContain('extendedKeyUsage=serverAuth\\nkeyUsage')
        ->toContain("echo '".base64_encode('authority-cert')."' | base64 -d > \"\$work/ca.crt\"")
        ->toContain("echo '".base64_encode('authority-key')."' | base64 -d > \"\$work/ca.key\"")
        ->and($database->fresh()->settings->certificates)->toMatchArray(['ca.crt' => 'authority-cert', 'server.crt' => 'server.crt-content']);
});

it('keeps the old certificates when generation fails', function () {
    $database = idleDatabase('mysql', 1);
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'old-ca'])]);
    fakeSsh()->respond('', 1);

    expect(fn () => resolve(IssueCertificates::class)->handle($database->fresh()))->toThrow(RuntimeException::class)
        ->and($database->fresh()->settings->certificates)->toBe(['ca.crt' => 'old-ca']);
});
