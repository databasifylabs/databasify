<?php

declare(strict_types=1);

use App\Actions\Databases\Monitoring\EvaluateResourceAlerts;
use App\Enums\Databases\ResourceMetric;
use App\Events\DatabaseAlertRaised;
use App\Models\DatabaseNode;
use App\Models\NodeMetricSample;
use Illuminate\Support\Facades\Event;

/**
 * @param  list<float>  $cpuOldestFirst
 */
function cpuSamples(DatabaseNode $node, array $cpuOldestFirst): void
{
    collect($cpuOldestFirst)->each(fn (float $cpu, int $minute) => NodeMetricSample::query()->create([
        'database_node_id' => $node->id,
        'sampled_at' => now()->subMinutes(count($cpuOldestFirst) - $minute),
        'cpu_percent' => $cpu,
        'memory_percent' => 40,
        'disk_percent' => 20,
    ]));
}

test('an alert opens after five minutes at or above ninety percent', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $node = DatabaseNode::factory()->create();
    cpuSamples($node, [91, 95, 90, 97, 99]);

    app(EvaluateResourceAlerts::class)->handle($node->database);

    expect($node->openAlerts()->sole()->metric)->toBe(ResourceMetric::Cpu);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert) => $alert->subject === "{$node->server->name} on {$node->database->name}: CPU above 90%");
});

test('a single dip below ninety percent keeps the alert closed', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $node = DatabaseNode::factory()->create();
    cpuSamples($node, [95, 95, 89, 97, 99]);

    app(EvaluateResourceAlerts::class)->handle($node->database);

    expect($node->openAlerts()->exists())->toBeFalse();
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

test('an open alert resolves once usage drops below ninety percent', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $node = DatabaseNode::factory()->create();
    $alert = $node->raiseAlert(ResourceMetric::Cpu);
    cpuSamples($node, [95, 95, 95, 95, 40]);

    app(EvaluateResourceAlerts::class)->handle($node->database);

    expect($alert->refresh()->resolved_at)->not->toBeNull();
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $raised) => str_ends_with($raised->subject, 'CPU back to normal'));
});
