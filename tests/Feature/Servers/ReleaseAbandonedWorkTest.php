<?php

declare(strict_types=1);

use App\Enums\Servers\ServerLogStatus;
use App\Models\ServerLog;

it('fails logs and releases operations whose worker died long ago', function () {
    $database = idleDatabase('postgres', 1);
    $database->startOperation('Upgrade');
    $database->update(['operation_started_at' => now()->subDay()]);
    $abandoned = ServerLog::factory()->create(['status' => ServerLogStatus::Running, 'output' => '', 'created_at' => now()->subDay()]);
    $running = ServerLog::factory()->create(['status' => ServerLogStatus::Running, 'output' => '']);

    $this->artisan('servers:release-abandoned-work')->assertSuccessful();

    expect($abandoned->fresh())->status->toBe(ServerLogStatus::Failed)->output->toBe("▸ the worker stopped before this finished\n")
        ->and($running->fresh()->status)->toBe(ServerLogStatus::Running)
        ->and($database->fresh()->operation)->toBeNull();
});

it('leaves an operation that started recently', function () {
    $database = idleDatabase('postgres', 1);
    $database->startOperation('Upgrade');

    $this->artisan('servers:release-abandoned-work')->assertSuccessful();

    expect($database->fresh()->operation)->toBe('Upgrade');
});
