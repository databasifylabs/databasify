<?php

declare(strict_types=1);

use App\Actions\Databases\Network\QueueTrustedSources;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('starts an operation and queues the firewall for it', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([ApplyTrustedSourcesJob::class]);

    resolve(QueueTrustedSources::class)->handle($database);

    expect($database->fresh()->status)->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(ApplyTrustedSourcesJob::class, fn (ApplyTrustedSourcesJob $job): bool => $job->database->is($database));
});

it('queues nothing when another operation is still running', function () {
    $database = newDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([ApplyTrustedSourcesJob::class]);

    expect(fn () => resolve(QueueTrustedSources::class)->handle($database))->toThrow(ValidationException::class, 'busy pending');
    Queue::assertNothingPushed();
});
