<?php

declare(strict_types=1);

use App\Enums\Servers\ServerLogStatus;
use App\Models\ServerLog;

it('prunes finished logs older than the retention window and keeps running, busy and recent ones', function () {
    $database = idleDatabase('valkey', 1);
    $busy = idleDatabase('valkey', 1, name: 'busy');
    $busy->startOperation('Upgrade');
    $old = now()->subDays(ServerLog::RETENTION_DAYS + 1);
    $pruned = ServerLog::factory()->create(['database_id' => $database->id, 'created_at' => $old]);
    $failed = ServerLog::factory()->create(['status' => ServerLogStatus::Failed, 'exit_code' => 1, 'created_at' => $old]);
    $running = ServerLog::factory()->create(['status' => ServerLogStatus::Running, 'created_at' => $old]);
    $ofBusy = ServerLog::factory()->create(['database_id' => $busy->id, 'created_at' => $old]);
    $recent = ServerLog::factory()->create(['created_at' => now()->subDays(ServerLog::RETENTION_DAYS - 1)]);

    $this->artisan('model:prune', ['--model' => [ServerLog::class]])->assertSuccessful();

    expect(ServerLog::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$running, $ofBusy, $recent])->pluck('id')->sort()->values()->all())
        ->not->toContain($pruned->id, $failed->id);
});
