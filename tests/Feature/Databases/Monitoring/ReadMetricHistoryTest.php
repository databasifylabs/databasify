<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\NodeMetricSample;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('serves bucketed history per node', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $node = $database->seed();
    $start = now()->startOfHour()->subHours(2);
    foreach ([0, 2, 6, 8] as $minute) {
        NodeMetricSample::query()->create(['database_node_id' => $node->id, 'sampled_at' => $start->copy()->addMinutes($minute), 'cpu_percent' => $minute * 10]);
    }
    NodeMetricSample::query()->create(['database_node_id' => $node->id, 'sampled_at' => now()->subDays(2), 'cpu_percent' => 99]);

    $response = $this->getJson(projectUrl("/databases/{$database->id}/metrics/history?period=6h"))->assertOk();

    expect($response->json('bucket_seconds'))->toBe(300)
        ->and($response->json('nodes.0.name'))->toBe($node->server->name)
        ->and(collect($response->json('points'))->pluck("values.{$node->id}.cpu_percent")->all())->toBe([10, 70]);
    $this->getJson(projectUrl("/databases/{$database->id}/metrics/history?period=2y"))->assertUnprocessable();
});

it('averages each minute of the last hour per node and leaves read-only nodes out', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 2);
    [$first, $second] = $database->nodes()->with('server')->get()->all();
    $reader = withReadOnlyNode($database, ['name' => 'reports']);
    $minute = now()->startOfMinute()->subMinutes(5);
    NodeMetricSample::query()->create(['database_node_id' => $first->id, 'sampled_at' => $minute, 'cpu_percent' => 10, 'connections' => 4]);
    NodeMetricSample::query()->create(['database_node_id' => $first->id, 'sampled_at' => $minute->copy()->addSeconds(30), 'cpu_percent' => 15, 'connections' => 6]);
    NodeMetricSample::query()->create(['database_node_id' => $second->id, 'sampled_at' => $minute->copy()->addSeconds(10), 'cpu_percent' => 50]);
    NodeMetricSample::query()->create(['database_node_id' => $reader->id, 'sampled_at' => $minute, 'cpu_percent' => 99]);
    NodeMetricSample::query()->create(['database_node_id' => $first->id, 'sampled_at' => now()->subHours(2), 'cpu_percent' => 99]);

    $response = $this->getJson(projectUrl("/databases/{$database->id}/metrics/history"))->assertOk();

    expect($response->json('period'))->toBe('1h')
        ->and($response->json('bucket_seconds'))->toBe(60)
        ->and($response->json('nodes'))->toBe([['id' => $first->id, 'name' => $first->server->name], ['id' => $second->id, 'name' => $second->server->name]])
        ->and($response->json('points'))->toHaveCount(1)
        ->and($response->json('points.0.t'))->toBe($minute->toIso8601String())
        ->and($response->json("points.0.values.{$first->id}"))->toBe(['cpu_percent' => 12.5, 'load_1m' => null, 'memory_percent' => null, 'disk_percent' => null, 'connections' => 5, 'ops_per_second' => null])
        ->and($response->json("points.0.values.{$second->id}.cpu_percent"))->toBe(50)
        ->and($response->json("points.0.values.{$reader->id}"))->toBeNull();
});

it('serves the history of a single node including read-only nodes', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database, ['name' => 'reports']);
    NodeMetricSample::query()->create(['database_node_id' => $database->seed()->id, 'sampled_at' => now()->subMinutes(3), 'cpu_percent' => 20]);
    NodeMetricSample::query()->create(['database_node_id' => $reader->id, 'sampled_at' => now()->subHours(20), 'disk_percent' => 70]);

    $response = $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$reader->id}/metrics/history?period=24h"))->assertOk();

    expect($response->json('bucket_seconds'))->toBe(900)
        ->and($response->json('nodes'))->toBe([['id' => $reader->id, 'name' => 'reports']])
        ->and(collect($response->json('points'))->pluck("values.{$reader->id}.disk_percent")->all())->toBe([70]);
    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$reader->id}/metrics/history?period=1y"))->assertUnprocessable()->assertJsonValidationErrors('period');
});

it('hides the history of a node from another database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $other = idleDatabase('valkey', 1, DatabaseMode::Single, 'other');

    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$other->seed()->id}/metrics/history"))->assertNotFound();
});

function weekOfMinuteSamples(Database $database): void
{
    $nodes = $database->nodes()->get();
    $start = now();

    $nodes->each(function (DatabaseNode $node, int $index) use ($start): void {
        collect(range(0, 7 * 24 * 60 - 1))
            ->map(fn (int $minute): array => [
                'id' => (string) Str::uuid(),
                'database_node_id' => $node->id,
                'sampled_at' => $start->copy()->subMinutes($minute)->toDateTimeString(),
                'cpu_percent' => ($minute * 7 + $index * 13) % 100,
                'load_1m' => $index === 0 ? null : (($minute * 3) % 40) / 8,
                'memory_percent' => $minute % 5 === 0 ? null : 30 + ($minute + $index) % 60,
                'disk_percent' => 42.5,
                'connections' => $minute % 50,
                'ops_per_second' => (($minute * 3) % 40) / 4,
            ])
            ->chunk(500)
            ->each(fn (Collection $rows) => DB::table('node_metric_samples')->insert($rows->all()));
    });
}

function metricHistoryFixture(string $json, Database $database): array
{
    $ids = $database->nodes()->get()->modelKeys();

    return json_decode(str_replace($ids, array_map(fn (int $index): string => 'node-'.$index, array_keys($ids)), $json), true, flags: JSON_THROW_ON_ERROR);
}

it('returns the same buckets as before', function () {
    $this->travelTo(Carbon::parse('2026-03-10 12:00:00'));
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 7);
    weekOfMinuteSamples($database);

    $response = $this->getJson(projectUrl("/databases/{$database->id}/metrics/history?period=7d"))->assertOk();

    $actual = metricHistoryFixture($response->content(), $database);
    $expected = json_decode((string) file_get_contents(base_path('tests/Fixtures/metric-history-week.json')), true, flags: JSON_THROW_ON_ERROR);
    expect(Arr::except($actual, ['nodes', 'points']))->toBe(Arr::except($expected, ['nodes', 'points']))
        ->and(array_column($actual['nodes'], 'id'))->toBe(array_column($expected['nodes'], 'id'))
        ->and(array_column($actual['points'], 't'))->toBe(array_column($expected['points'], 't'));
    foreach ($expected['points'] as $index => $point) {
        foreach ($point['values'] as $nodeId => $metrics) {
            foreach ($metrics as $metric => $value) {
                $value === null
                    ? expect($actual['points'][$index]['values'][$nodeId][$metric])->toBeNull()
                    : expect($actual['points'][$index]['values'][$nodeId][$metric])->toEqualWithDelta($value, 0.01);
            }
        }
        expect(array_keys($actual['points'][$index]['values']))->toEqualCanonicalizing(array_keys($point['values']));
    }
});

it('reads the history in one query', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 3);
    $database->nodes()->get()->each(fn (DatabaseNode $node) => NodeMetricSample::query()->create(['database_node_id' => $node->id, 'sampled_at' => now()->subMinutes(5), 'cpu_percent' => 10]));

    DB::enableQueryLog();
    $this->getJson(projectUrl("/databases/{$database->id}/metrics/history"))->assertOk();

    $sampleQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'node_metric_samples'));
    expect($sampleQueries)->toHaveCount(1)
        ->and($sampleQueries->first()['query'])->toContain('group by');
});
