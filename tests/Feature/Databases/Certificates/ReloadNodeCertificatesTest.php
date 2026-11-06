<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\ReloadNodeCertificates;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Servers\ServerLogStatus;

it('writes the node its own certificate beside the database authority and reloads tls in place', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database);
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'authority-cert', 'server.crt' => 'member-cert', 'server.key' => 'member-key'])->withNodeCertificates($reader->id, ['server.crt' => 'reader-cert', 'server.key' => 'reader-key'])]);
    $ssh = fakeSsh();

    resolve(ReloadNodeCertificates::class)->handle($database->fresh(), $reader->fresh());

    expect($ssh->runs[0]['server'])->toBe($reader->server->name)
        ->and($ssh->scriptAt(0))
        ->toContain("echo '".base64_encode('authority-cert')."' | base64 -d | put_file '/srv/mariadb/tls/ca.crt' 600 \"\$engine_uid\" \"\$engine_gid\"")
        ->toContain("echo '".base64_encode('reader-cert')."' | base64 -d | put_file '/srv/mariadb/tls/server.crt' 600 \"\$engine_uid\" \"\$engine_gid\"")
        ->toContain("echo '".base64_encode('reader-key')."' | base64 -d | put_file '/srv/mariadb/tls/server.key' 600 \"\$engine_uid\" \"\$engine_gid\"")
        ->toContain('use_engine_ids "$(stat -c %u:%g "$dir")"')
        ->not->toContain(base64_encode('member-cert'))
        ->toContain("docker exec -i databasify-mariadb databasify step reload-tls <<'RELOAD'\nset -euo pipefail\ndir=/srv/mariadb\n")
        ->toContain('msql "FLUSH SSL"')
        ->and($reader->server->serverLogs()->sole())
        ->name->toBe("{$reader->server->name}: reloading certificates")
        ->status->toBe(ServerLogStatus::Succeeded);
});

it('reloads the shared sentinel certificate on members', function () {
    $database = idleDatabase('redis', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'authority-cert', 'server.pem' => 'member-pem'])]);
    $ssh = fakeSsh();

    resolve(ReloadNodeCertificates::class)->handle($database->fresh(), $database->seed());

    expect($ssh->scriptAt(0))
        ->toContain("echo '".base64_encode('member-pem')."' | base64 -d | put_file '/srv/redis/tls/server.pem' 600 \"\$engine_uid\" \"\$engine_gid\"")
        ->not->toContain('tls/server.crt')
        ->toContain('CONFIG SET tls-cert-file /srv/redis/tls/server.pem tls-key-file /srv/redis/tls/server.pem tls-ca-cert-file /srv/redis/tls/ca.crt');
});
