<?php

declare(strict_types=1);

use App\Actions\Databases\Network\AddTrustedSource;
use App\DTOs\Databases\NewTrustedSource;
use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('allows a range once and queues the firewall for each request', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([ApplyTrustedSourcesJob::class]);

    resolve(AddTrustedSource::class)->handle($database, NewTrustedSource::normalized('203.0.113.0/24', 'office'));
    $database->finishOperation();
    resolve(AddTrustedSource::class)->handle($database, NewTrustedSource::normalized('203.0.113.0/24', 'renamed'));

    expect($database->trustedSources()->get(['cidr', 'label'])->toArray())->toBe([['cidr' => '203.0.113.0/24', 'label' => 'office']])
        ->and($database->fresh()->operation)->toBe('Allow 203.0.113.0/24');
    Queue::assertPushed(ApplyTrustedSourcesJob::class, fn (ApplyTrustedSourcesJob $job): bool => $job->database->is($database));
    Queue::assertPushed(ApplyTrustedSourcesJob::class, 2);
});

it('refuses new trusted sources while the database is busy', function () {
    $database = newDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([ApplyTrustedSourcesJob::class]);

    expect(fn () => resolve(AddTrustedSource::class)->handle($database, NewTrustedSource::normalized('203.0.113.7')))->toThrow(ValidationException::class, 'busy with Create MySQL database')
        ->and($database->trustedSources()->count())->toBe(0);
    Queue::assertNothingPushed();
});
