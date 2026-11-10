<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\EnsureMemberCertificates;
use App\Actions\Databases\Certificates\EnsureNodeCertificates;
use App\Actions\Databases\Certificates\QueueCertificateRenewal;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RenewCertificatesJob;
use Illuminate\Support\Facades\Queue;

it('keeps member certificates that cover every name', function () {
    $database = withCurrentCertificates(idleDatabase('mysql', 2));
    $ssh = fakeSsh();

    resolve(EnsureMemberCertificates::class)->handle($database);

    expect($ssh->runs)->toBe([]);
});

it('re-issues MySQL certificates that lack an RSA key with an RSA key', function () {
    $database = idleDatabase('mysql', 2);
    $reader = withReadOnlyNode($database, ['name' => 'reports']);
    $database = withCurrentCertificates($database);
    $database->update(['settings' => $database->settings
        ->withCertificates([...$database->settings->certificates, 'server.crt' => issuedPem($database->certificateNames())])
        ->withNodeCertificates($reader->id, ['server.crt' => issuedPem($reader->certificateNames()), 'server.key' => 'key'])]);
    $database = $database->fresh();
    $ssh = fakeSsh()->respond(certificateOutput('server.crt', 'server.key'))->respond(certificateOutput('server.crt', 'server.key'));

    expect(resolve(EnsureMemberCertificates::class)->handle($database))->toBeTrue()
        ->and(resolve(EnsureNodeCertificates::class)->handle($database))->toBeTrue()
        ->and($ssh->runs)->toHaveCount(2)
        ->and($ssh->scriptAt(0))->toContain('rsa_keygen_bits:2048')
        ->and($ssh->scriptAt(1))->toContain('rsa_keygen_bits:2048');
});

it('keeps MySQL certificates that already have an RSA key', function () {
    $database = withCurrentCertificates(idleDatabase('mysql', 2));
    $ssh = fakeSsh();

    expect(resolve(EnsureMemberCertificates::class)->handle($database))->toBeFalse()
        ->and($ssh->runs)->toBe([]);
});

it('re-issues member certificates under the same authority once the database gets hostnames', function () {
    $database = withCurrentCertificates(idleDatabase('mysql', 2, name: 'app-db'));
    $database = withZone($database);
    $ssh = fakeSsh()->respond(certificateOutput('server.crt', 'server.key'));

    resolve(EnsureMemberCertificates::class)->handle($database);

    expect($ssh->runs)->toHaveCount(1)
        ->and($ssh->scriptAt(0))->toContain('app-db.db.example.com')->toContain('replica-app-db.db.example.com')->toContain(base64_encode('authority-key'))
        ->and($database->fresh()->settings)->certificate('server.crt')->toBe('server.crt-content')->certificate('ca.crt')->toBe('authority-cert');
});

it('queues a renewal only for an idle ready database with a stale certificate', function () {
    $stale = idleDatabase('valkey', 1, DatabaseMode::Single, 'stale');
    $current = withCurrentCertificates(idleDatabase('valkey', 1, DatabaseMode::Single, 'current'));
    $busy = idleDatabase('valkey', 1, DatabaseMode::Single, 'busy');
    collect([$stale, $current, $busy])->each(fn ($database) => $database->update(['status' => DatabaseStatus::Ready]));
    $creating = idleDatabase('valkey', 1, DatabaseMode::Single, 'creating');
    $busy->startOperation('Upgrade');
    Queue::fake([RenewCertificatesJob::class]);

    $this->artisan('databases:renew-certificates')->expectsOutputToContain('Queued 1 certificate renewals.')->assertSuccessful();

    expect(resolve(QueueCertificateRenewal::class)->handle($current))->toBeFalse()
        ->and(resolve(QueueCertificateRenewal::class)->handle($creating))->toBeFalse()
        ->and($stale->fresh()->operation)->toBe('Renew certificates')
        ->and($busy->fresh()->operation)->toBe('Upgrade');
    Queue::assertPushed(RenewCertificatesJob::class, fn (RenewCertificatesJob $job): bool => $job->database->is($stale));
});

it('leaves the certificates of an offline database for when it serves again', function () {
    $offline = idleDatabase('valkey', 1, DatabaseMode::Single, 'offline');
    $offline->update(['status' => DatabaseStatus::Offline]);
    Queue::fake([RenewCertificatesJob::class]);

    expect(resolve(QueueCertificateRenewal::class)->handle($offline))->toBeFalse()
        ->and($offline->fresh()->operation)->toBeNull();
    Queue::assertNothingPushed();
});
