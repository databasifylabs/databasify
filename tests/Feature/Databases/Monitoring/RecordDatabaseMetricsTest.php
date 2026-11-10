<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RecordDatabaseMetricsJob;
use App\Models\NodeMetricSample;
use Illuminate\Support\Facades\Queue;

it('prunes samples older than the retention window', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    NodeMetricSample::query()->create(['database_node_id' => $database->seed()->id, 'sampled_at' => now()->subDays(8)]);
    NodeMetricSample::query()->create(['database_node_id' => $database->seed()->id, 'sampled_at' => now()->subDay()]);

    $this->artisan('model:prune', ['--model' => [NodeMetricSample::class]])->assertSuccessful();

    expect(NodeMetricSample::query()->count())->toBe(1);
});

it('records metrics every minute for databases that are neither creating nor failed', function () {
    $ready = idleDatabase('valkey', 1, DatabaseMode::Single, 'ready');
    $ready->update(['status' => DatabaseStatus::Ready]);
    idleDatabase('valkey', 1, DatabaseMode::Single, 'broken')->update(['status' => DatabaseStatus::Failed]);
    newDatabase('valkey', 1, DatabaseMode::Single, 'creating');
    Queue::fake([RecordDatabaseMetricsJob::class]);
    $this->artisan('databases:record-metrics')->assertSuccessful();

    Queue::assertPushed(RecordDatabaseMetricsJob::class, 1);
    Queue::assertPushed(RecordDatabaseMetricsJob::class, fn (RecordDatabaseMetricsJob $job): bool => $job->database->is($ready) && $job->uniqueId() === (string) $ready->id);
});
