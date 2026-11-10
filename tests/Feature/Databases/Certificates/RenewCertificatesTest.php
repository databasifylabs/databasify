<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\QueueCertificateRenewal;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RenewCertificatesJob;
use Illuminate\Support\Facades\Queue;

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
