<?php

declare(strict_types=1);

use App\Actions\Databases\Network\RemoveTrustedSource;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('forgets the range and queues the firewall without it', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $source = $database->trustedSources()->create(['cidr' => '203.0.113.0/24']);
    $database->trustedSources()->create(['cidr' => '198.51.100.7/32']);
    Queue::fake([ApplyTrustedSourcesJob::class]);

    resolve(RemoveTrustedSource::class)->handle($source);

    expect($database->trustedCidrs())->toBe(['198.51.100.7/32'])
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(ApplyTrustedSourcesJob::class, fn (ApplyTrustedSourcesJob $job): bool => $job->database->is($database));
});

it('keeps the range while the database is busy', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $source = $database->trustedSources()->create(['cidr' => '203.0.113.0/24']);
    $database->update(['status' => DatabaseStatus::Upgrading]);
    Queue::fake([ApplyTrustedSourcesJob::class]);

    expect(fn () => resolve(RemoveTrustedSource::class)->handle($source))->toThrow(ValidationException::class, 'busy upgrading')
        ->and($source->fresh())->not->toBeNull();
    Queue::assertNothingPushed();
});
