<?php

declare(strict_types=1);

use App\Actions\Databases\Monitoring\EvaluateResourceAlerts;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\ResourceMetric;
use App\Events\DatabaseAlertRaised;
use App\Models\DatabaseNode;
use App\Models\NodeAlert;
use App\Models\NodeMetricSample;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('raises one alert after five minutes above 90 percent and clears it when usage drops', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $node = $database->seed();
    $alerts = resolve(EvaluateResourceAlerts::class);

    sampleCpu($node, 95, 96, 91, 89);
    $alerts->handle($database);
    expect(NodeAlert::query()->count())->toBe(0);

    $this->travel(10)->minutes();
    sampleCpu($node, 92, 93, 94, 95, 97);
    $alerts->handle($database);
    $alerts->handle($database);
    expect(NodeAlert::query()->open()->sole()->metric)->toBe(ResourceMetric::Cpu)
        ->and($this->get(projectUrl("/databases/{$database->id}"))->viewData('page')['props']['database']['data']['alerts'][0]['label'])->toBe('CPU');

    $this->travel(1)->minutes();
    sampleCpu($node, 40);
    $alerts->handle($database);

    expect(NodeAlert::query()->open()->count())->toBe(0);
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 2);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $event): bool => str_contains($event->subject, 'CPU above 90%'));
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $event): bool => str_contains($event->subject, 'CPU back to normal'));
});

it('names the hot node and ignores nodes with too few or missing samples', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('mysql', 3);
    [$hot, $short, $partial] = $database->nodes()->get()->all();
    $hot->update(['name' => 'db-a']);
    $sample = fn (DatabaseNode $node, int $minutesAgo, ?float $memory) => NodeMetricSample::query()->create(['database_node_id' => $node->id, 'sampled_at' => now()->subMinutes($minutesAgo), 'memory_percent' => $memory]);
    foreach (range(5, 1) as $minutes) {
        $sample($hot, $minutes, 93);
        $sample($partial, $minutes, $minutes === 3 ? null : 97);
    }
    foreach (range(4, 1) as $minutes) {
        $sample($short, $minutes, 99);
    }

    resolve(EvaluateResourceAlerts::class)->handle($database);

    expect(NodeAlert::query()->open()->get()->map(fn (NodeAlert $alert): array => [$alert->database_node_id, $alert->metric])->all())->toBe([[$hot->id, ResourceMetric::Memory]]);
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 1);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $event): bool => $event->subject === 'db-a on main: Memory above 90%'
        && $event->message === 'Memory usage on db-a has stayed at or above 90% for the last 5 minutes (now 93%).');
});

it('keeps an open alert without raising it again while usage stays high or no samples arrive', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $node = $database->seed();
    $node->alerts()->create(['metric' => ResourceMetric::Disk, 'opened_at' => now()->subHour()]);

    resolve(EvaluateResourceAlerts::class)->handle($database);
    NodeMetricSample::query()->create(['database_node_id' => $node->id, 'sampled_at' => now(), 'disk_percent' => 90]);
    resolve(EvaluateResourceAlerts::class)->handle($database);

    expect(NodeAlert::query()->open()->sole()->metric)->toBe(ResourceMetric::Disk);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});
