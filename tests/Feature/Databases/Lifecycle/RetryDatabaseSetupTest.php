<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\BootstrapDatabase;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('runs the setup of a failed database again after clearing its nodes', function () {
    $database = idleDatabase('mysql', 3);
    $database->update(['status' => DatabaseStatus::Failed]);

    $this->post("/databases/{$database->id}/retry-setup")->assertSessionHasNoErrors();

    expect($database->fresh()->status)->toBe(DatabaseStatus::Creating)
        ->and($database->fresh()->operation)->toBe('Retry MySQL setup');
    Queue::assertPushed(BootstrapDatabaseJob::class, fn (BootstrapDatabaseJob $job): bool => $job->clearFirst && $job->database->is($database));
});

it('only retries a database whose setup failed', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Ready]);

    $this->post("/databases/{$database->id}/retry-setup")
        ->assertSessionHasErrors(['database' => "{$database->name} finished its setup, there is nothing to retry."]);
});

it('erases what the failed setup left on every node before setting up again', function () {
    $database = idleDatabase('mysql', 2);
    $database->startOperation('Retry MySQL setup');
    $ssh = fakeSsh()->respond()->respond()->respond('openssl: command not found', 1);

    expect(fn () => resolve(BootstrapDatabase::class)->handle($database, clearFirst: true))->toThrow(RemoteScriptFailed::class);
    expect($ssh->scriptAt(0))->toContain('docker rm -v -f databasify-mysql')->toContain('rm -rf "$dir"')
        ->and($ssh->scriptAt(1))->toContain('rm -rf "$dir"')
        ->and($ssh->runs[0]['server'])->not->toBe($ssh->runs[1]['server'])
        ->and($database->serverLogs()->pluck('name')->all())->toContain("{$ssh->runs[0]['server']}: clearing what the failed setup left");
});
