<?php

declare(strict_types=1);

use App\Actions\Updates\StartUpdate;
use App\Enums\Updates\UpdateState;
use App\Enums\Updates\UpdateStepState;
use App\Services\Updates\HostUpdater;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->updater = installedUpdater();
    storeRelease('0.2.0', '');
    config()->set('app.version', '0.1.0');
    config()->set('databasify.updates.pinned', null);
});

it('writes the request when an update is possible', function () {
    resolve(StartUpdate::class)->handle();

    expect(file_exists($this->updater.'/request/update'))->toBeTrue();
});

it('allows an update on an install that tracks latest', function () {
    config()->set('databasify.updates.pinned', 'latest');

    resolve(StartUpdate::class)->handle();

    expect(file_exists($this->updater.'/request/update'))->toBeTrue();
});

it('refuses without writing a request', function (string $reason) {
    match ($reason) {
        'pinned' => config()->set('databasify.updates.pinned', '0.1.0'),
        'no helper' => unlink($this->updater.'/installed'),
        'already running' => file_put_contents($this->updater.'/status.json', runningStatusJson()),
        'up to date' => config()->set('app.version', '0.2.0'),
    };

    expect(fn () => resolve(StartUpdate::class)->handle())->toThrow(ValidationException::class)
        ->and(file_exists($this->updater.'/request/update'))->toBeFalse();
})->with(['pinned', 'no helper', 'already running', 'up to date']);

it('refuses while an earlier request waits for the host', function () {
    file_put_contents($this->updater.'/request/update', 'earlier');

    expect(fn () => resolve(StartUpdate::class)->handle())->toThrow(ValidationException::class)
        ->and(file_get_contents($this->updater.'/request/update'))->toBe('earlier');
});

it('reads the status the update writes', function () {
    file_put_contents($this->updater.'/status.json', updateStatusJson('failed', true, 'Waiting until healthy'));

    $status = resolve(HostUpdater::class)->status();

    expect($status?->state)->toBe(UpdateState::Failed)
        ->and($status?->rolledBack)->toBeTrue()
        ->and($status?->to)->toBe('0.2.0')
        ->and($status?->failedStep()?->title)->toBe('Waiting until healthy')
        ->and($status?->steps[0]->state)->toBe(UpdateStepState::Done);
});

it('treats a missing, empty or half-written status as no status', function (?string $contents) {
    if ($contents !== null) {
        file_put_contents($this->updater.'/status.json', $contents);
    }

    expect(resolve(HostUpdater::class)->status())->toBeNull();
})->with([null, '', '{"state": "running", "steps": [', '{"state": "paused"}', '{"state": "running", "started_at": "not a date"}', '{"state": "failed", "started_at": "2026-11-20T03:14:02Z", "finished_at": "soon"}']);

it('reads the latest log and returns nothing without one', function () {
    expect(resolve(HostUpdater::class)->log())->toBe('');

    file_put_contents($this->updater.'/update.log', "line one\n");

    expect(resolve(HostUpdater::class)->log())->toBe("line one\n");
});
