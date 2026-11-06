<?php

declare(strict_types=1);

use App\DTOs\Metrics\MetricSample;
use App\DTOs\Metrics\NodeMetrics;
use App\Enums\Databases\DatabaseMode;
use App\Models\NodeMetricSample;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->node = idleDatabase('mysql', 1, DatabaseMode::Single)->seed();
    $this->at = CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC');
    $this->previous = NodeMetricSample::query()->create(['database_node_id' => $this->node->id, 'sampled_at' => $this->at->subMinute(), 'query_total' => 1000]);
});

it('derives queries per second from the previous sample', function () {
    $sample = MetricSample::from(new NodeMetrics($this->node->id, 'db-1', true, ['questions' => '1600', 'threads_connected' => '9']), $this->at, $this->previous);

    expect($sample)->opsPerSecond->toBe(10.0)->queryTotal->toBe(1600)->connections->toBe(9);
});

it('skips the rate after a counter reset or without elapsed time', function () {
    $reset = MetricSample::from(new NodeMetrics($this->node->id, 'db-1', true, ['questions' => '10']), $this->at, $this->previous);
    $instant = MetricSample::from(new NodeMetrics($this->node->id, 'db-1', true, ['questions' => '2000']), $this->at->subMinute(), $this->previous);
    $first = MetricSample::from(new NodeMetrics($this->node->id, 'db-1', true, ['questions' => '2000']), $this->at, null);

    expect($reset->opsPerSecond)->toBeNull()
        ->and($instant->opsPerSecond)->toBeNull()
        ->and($first->opsPerSecond)->toBeNull();
});
