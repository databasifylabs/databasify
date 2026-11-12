<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('runs every command on its own schedule on one server only', function () {
    $events = collect(resolve(Schedule::class)->events())
        ->mapWithKeys(fn (Event $event): array => [(string) preg_replace("/^.*'artisan' /", '', (string) $event->command) => [$event->expression, $event->withoutOverlapping, $event->onOneServer]]);

    expect($events->all())->toBe([
        'databases:advance-flows' => ['* * * * *', true, true],
        'databases:refresh-status' => ['* * * * *', true, true],
        'databases:record-metrics' => ['* * * * *', true, true],
        'databases:refresh-migrations' => ['* * * * *', true, true],
        'databases:refresh-recovery' => ['*/5 * * * *', true, true],
        'networks:reconcile' => ['*/5 * * * *', true, true],
        'servers:release-abandoned-work' => ['*/5 * * * *', true, true],
        'databases:apply-maintenance' => ['0 * * * *', false, true],
        'databases:check-upgrades' => ['0 0 * * *', false, true],
        'updates:check' => ['0 0 * * *', false, true],
        'databases:renew-certificates' => ['0 2 * * *', true, true],
        'horizon:snapshot' => ['*/5 * * * *', false, true],
        "model:prune --model='App\\Models\\NodeMetricSample' --model='App\\Models\\ServerLog'" => ['0 0 * * *', false, true],
    ]);
});

it('expires every overlap lock within the task interval', function () {
    $maximumMinutes = fn (Event $event): int => str_contains((string) $event->command, 'databases:renew-certificates') ? 240 : 10;

    $events = collect(resolve(Schedule::class)->events())->filter(fn (Event $event): bool => $event->withoutOverlapping);

    expect($events)->not->toBeEmpty();
    $events->each(fn (Event $event) => expect($event->expiresAt)->toBeLessThanOrEqual($maximumMinutes($event)));
});
