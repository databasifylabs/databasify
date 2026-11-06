<?php

declare(strict_types=1);

use App\Enums\Queues\QueueGroup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\File;

/**
 * @return list<ReflectionClass<object>>
 */
function queuedJobs(): array
{
    return collect(File::allFiles(app_path('Jobs')))
        ->map(fn (SplFileInfo $file): string => 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, ShouldQueue::class))
        ->map(fn (string $class): ReflectionClass => new ReflectionClass($class))
        ->values()
        ->all();
}

function attributeOf(ReflectionClass $job, string $attribute): ?object
{
    return ($job->getAttributes($attribute)[0] ?? null)?->newInstance();
}

it('runs every job on a queue group whose supervisor outlasts it', function () {
    expect(queuedJobs())->not->toBeEmpty();

    foreach (queuedJobs() as $job) {
        $queue = (string) attributeOf($job, Queue::class)?->queue;
        $supervisorTimeout = (int) config("horizon.defaults.{$queue}.timeout");

        expect(QueueGroup::tryFrom($queue))->not->toBeNull("{$job->getShortName()} has no queue group")
            ->and(config("horizon.defaults.{$queue}.connection"))->toBe('redis')
            ->and(attributeOf($job, Timeout::class)?->timeout ?? $supervisorTimeout)->toBeLessThanOrEqual($supervisorTimeout, "{$job->getShortName()} outlasts its supervisor");
    }
});

it('never hands a job to a second worker while its supervisor may still run it', function () {
    $longest = collect(QueueGroup::cases())->max(fn (QueueGroup $group): int => (int) config("horizon.defaults.{$group->value}.timeout"));

    expect((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan($longest);
});
