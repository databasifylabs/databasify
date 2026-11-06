<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\RedeployDatabaseNode;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\RedeployDatabaseNodeJob;

it('issues missing read-only certificates before redeploying a node', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database, ['name' => 'main-ro-1']);
    $issued = withReadOnlyNode($database, ['name' => 'main-ro-2']);
    $database = withCurrentCertificates($database);
    $database->update(['settings' => $database->settings->withoutNodeCertificates($reader->id)]);
    $database->startOperation('Redeploy');
    withImage('mysql', '8.4.99');
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.crt', 'server.key'));

    runJob(new RedeployDatabaseNodeJob($database, $reader));

    $generated = collect($ssh->runs)->filter(fn (array $run): bool => str_contains($run['script'], 'subjectAltName='));
    expect($generated)->toHaveCount(1)
        ->and($generated->first()['script'])->toContain($reader->server->public_ip)->not->toContain($issued->server->public_ip)
        ->and($database->fresh()->settings->nodeCertificate($reader->id, 'server.crt'))->toBe('server.crt-content')
        ->and($ssh->runs[1]['server'])->toBe($reader->server->name)
        ->and($ssh->scriptAt(1))->toContain(base64_encode('server.crt-content'))
        ->and($ssh->scriptAt(1))->toContain("image='{$database->image()->reference()}'")->not->toContain('8.4.99')
        ->and($database->fresh()->operation)->toBeNull();
});

it('frees the database and fails the server log when a redeploy step fails', function () {
    $database = withCurrentCertificates(idleDatabase('mysql', 1, DatabaseMode::Single));
    $database->startOperation('Redeploy');
    fakeSsh()->respond('E: Unable to locate package', 1);

    expect(fn () => runJob(new RedeployDatabaseNodeJob($database, $database->seed())))->toThrow(RuntimeException::class)
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->sole())->status->toBe(ServerLogStatus::Failed)->output->toContain('E: Unable to locate package');
});

it('reloads the node certificates after deploying when they were re-issued', function () {
    $database = withZone(withCurrentCertificates(idleDatabase('mysql', 1, DatabaseMode::Single, 'app-db')));
    $database->startOperation('Serve on app-db.db.example.com');
    $ssh = fakeSsh()->respond(certificateOutput('server.crt', 'server.key'));

    runJob(new RedeployDatabaseNodeJob($database, $database->seed()));

    $scripts = collect($ssh->runs)->pluck('script')->values();
    expect($scripts->first())->toContain('subjectAltName=')->toContain('app-db.db.example.com')
        ->and($scripts->first(fn (string $script): bool => str_contains($script, 'ALTER INSTANCE RELOAD TLS')))->toContain(base64_encode('server.crt-content'))
        ->and($database->fresh()->operation)->toBeNull();
});

it('does not reload certificates that were already current', function () {
    $database = withCurrentCertificates(idleDatabase('mysql', 1, DatabaseMode::Single));
    $database->startOperation('Redeploy');
    $ssh = fakeSsh();

    resolve(RedeployDatabaseNode::class)->handle($database, $database->seed());

    expect(collect($ssh->runs)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'ALTER INSTANCE RELOAD TLS') || str_contains($script, 'subjectAltName=')))->toBeEmpty();
});

it('moves the primary to a standby before redeploying it in place', function () {
    $database = withCurrentCertificates(idleDatabase('valkey', 2));
    [$primary] = $database->nodes()->with('server')->get()->all();
    $database->startOperation('Redeploy');
    $ssh = fakeSsh()->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")
        ->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");

    resolve(RedeployDatabaseNode::class)->handle($database, $primary);

    expect($ssh->runs[4]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(4))->toContain('SENTINEL FAILOVER')
        ->and($database->serverLogs()->pluck('name'))->toContain("moving the primary off {$primary->server->name}");
});
