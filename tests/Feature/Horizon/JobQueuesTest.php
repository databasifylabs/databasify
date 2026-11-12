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

/**
 * @return array<string, array{queue: list<string>, timeout: int, balance: string}>
 */
function horizonSupervisors(): array
{
    return config('horizon.defaults');
}

function supervisorOf(string $queue): string
{
    return (string) collect(horizonSupervisors())->search(fn (array $supervisor): bool => in_array($queue, $supervisor['queue'], true));
}

it('runs quick work and long server work in two auto-balanced supervisors', function () {
    expect(collect(horizonSupervisors())->map(fn (array $supervisor): array => $supervisor['queue'])->all())->toBe([
        'default' => ['monitoring', 'default'],
        'ssh' => ['databases', 'migrations', 'provisioning', 'terminals'],
    ])
        ->and(collect(horizonSupervisors())->pluck('balance')->unique()->all())->toBe(['auto'])
        ->and(array_keys(config('horizon.environments.*')))->toBe(['default', 'ssh']);
});

it('serves every queue group from exactly one supervisor', function () {
    $queues = collect(horizonSupervisors())->flatMap(fn (array $supervisor): array => $supervisor['queue']);

    expect($queues->sort()->values()->all())->toBe(collect(QueueGroup::cases())->map(fn (QueueGroup $group): string => $group->value)->sort()->values()->all());
});

it('runs every job on a supervisor that outlasts it', function () {
    expect(queuedJobs())->not->toBeEmpty();

    foreach (queuedJobs() as $job) {
        $queue = (string) attributeOf($job, Queue::class)?->queue;
        $supervisorTimeout = (int) config('horizon.defaults.'.supervisorOf($queue).'.timeout');

        expect(QueueGroup::tryFrom($queue))->not->toBeNull("{$job->getShortName()} has no queue group")
            ->and(supervisorOf($queue))->not->toBe('', "{$job->getShortName()} runs on a queue no supervisor serves")
            ->and(attributeOf($job, Timeout::class)?->timeout ?? $supervisorTimeout)->toBeLessThanOrEqual($supervisorTimeout, "{$job->getShortName()} outlasts its supervisor");
    }
});

it('never hands a job to a second worker while its supervisor may still run it', function () {
    $longest = collect(horizonSupervisors())->max(fn (array $supervisor): int => (int) $supervisor['timeout']);

    expect((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan($longest);
});
