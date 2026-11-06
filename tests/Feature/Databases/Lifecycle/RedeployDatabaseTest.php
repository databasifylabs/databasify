<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\RedeployDatabase;
use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\UpgradeDatabaseJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('redeploys every node on the release it already runs', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class]);

    resolve(RedeployDatabase::class)->handle($database, 'Apply configuration');

    expect($database->fresh()->operation)->toBe('Apply configuration');
    Queue::assertPushed(UpgradeDatabaseJob::class, fn (UpgradeDatabaseJob $job): bool => $job->database->is($database) && ! $job->installUpdate);
});

it('refuses to redeploy while another operation runs', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class]);
    $database->startOperation('Upgrade');

    expect(fn () => resolve(RedeployDatabase::class)->handle($database, 'Apply configuration'))->toThrow(ValidationException::class, 'busy with Upgrade');
    Queue::assertNothingPushed();
});
