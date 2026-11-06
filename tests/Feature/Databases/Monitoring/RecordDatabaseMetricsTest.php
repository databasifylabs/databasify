<?php

declare(strict_types=1);

use App\Actions\Databases\Monitoring\RecordDatabaseMetrics;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\ResourceMetric;
use App\Events\DatabaseAlertRaised;
use App\Jobs\Databases\RecordDatabaseMetricsJob;
use App\Models\NodeAlert;
use App\Models\NodeMetricSample;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('records percentages and derives mysql throughput from the question counter', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $node = $database->seed();
    $output = fn (int $questions): string => "cpus=1\ncpu_percent=12.5\nload_1m=0.30\nmemory_total_mb=2000\nmemory_available_mb=500\ndisk_total_mb=40000\ndisk_used_mb=10000\nthreads_connected=7\nquestions={$questions}\n";
    fakeSsh()->respond($output(1000))->respond($output(1600));

    $this->travelTo(now()->startOfMinute());
    resolve(RecordDatabaseMetrics::class)->handle($database);
    $this->travel(60)->seconds();
    resolve(RecordDatabaseMetrics::class)->handle($database);

    $samples = NodeMetricSample::query()->where('database_node_id', $node->id)->oldest('sampled_at')->get();
    expect($samples)->toHaveCount(2)
        ->and($samples[0])
        ->cpu_percent->toBe(12.5)
        ->memory_percent->toBe(75.0)
        ->disk_percent->toBe(25.0)
        ->connections->toBe(7)
        ->ops_per_second->toBeNull()
        ->and($samples[1]->ops_per_second)->toBe(10.0);
});

it('skips unreachable nodes and uses valkey operations directly', function () {
    $database = idleDatabase('valkey', 2);
    fakeSsh()->respond("cpu_percent=3\nconnected_clients=4\ninstantaneous_ops_per_sec=250\n")->respond('', 255);

    resolve(RecordDatabaseMetrics::class)->handle($database);

    expect(NodeMetricSample::query()->count())->toBe(1)
        ->and(NodeMetricSample::query()->sole())->ops_per_second->toBe(250.0)->connections->toBe(4);
});

it('prunes samples older than the retention window', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    NodeMetricSample::query()->create(['database_node_id' => $database->seed()->id, 'sampled_at' => now()->subDays(8)]);
    NodeMetricSample::query()->create(['database_node_id' => $database->seed()->id, 'sampled_at' => now()->subDay()]);

    $this->artisan('model:prune', ['--model' => [NodeMetricSample::class]])->assertSuccessful();

    expect(NodeMetricSample::query()->count())->toBe(1);
});

it('derives throughput from the transaction counter of postgres and mariadb', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    fakeSsh()->respond("threads_connected=3\nquestions=500\n")->respond("threads_connected=3\nquestions=800\n")->respond("threads_connected=3\nquestions=10\n");

    $this->travelTo(now()->startOfMinute());
    resolve(RecordDatabaseMetrics::class)->handle($database);
    $this->travel(30)->seconds();
    resolve(RecordDatabaseMetrics::class)->handle($database);
    $this->travel(30)->seconds();
    resolve(RecordDatabaseMetrics::class)->handle($database);

    expect(NodeMetricSample::query()->oldest('sampled_at')->pluck('ops_per_second')->all())->toBe([null, 10.0, null])
        ->and(NodeMetricSample::query()->oldest('sampled_at')->pluck('query_total')->all())->toBe([500, 800, 10]);
})->with(['postgres', 'mariadb']);

it('records metrics and evaluates alerts in the metrics job', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $node = $database->seed();
    foreach (range(4, 1) as $minutes) {
        NodeMetricSample::query()->create(['database_node_id' => $node->id, 'sampled_at' => now()->subMinutes($minutes), 'disk_percent' => 95]);
    }
    fakeSsh()->respond("disk_total_mb=1000\ndisk_used_mb=960\n");

    dispatch(new RecordDatabaseMetricsJob($database));

    expect(NodeMetricSample::query()->latest('sampled_at')->first()?->disk_percent)->toBe(96.0)
        ->and(NodeAlert::query()->open()->sole()->metric)->toBe(ResourceMetric::Disk);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => str_contains($alert->subject, 'Disk above 90%'));
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
