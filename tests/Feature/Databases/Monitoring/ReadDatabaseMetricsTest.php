<?php

declare(strict_types=1);

use App\Actions\Databases\Monitoring\ReadDatabaseMetrics;

it('reads host facts and engine metrics of every node and marks unreachable nodes', function () {
    $database = idleDatabase('mysql', 2);
    [$up, $down] = $database->nodes()->orderBy('position')->get()->all();
    $ssh = fakeEngine(fakeSsh()->respondWhen(fn (string $server, string $script): bool => $server === $down->server->name, '', 255))
        ->respondWhen(fn (string $server, string $script): bool => str_contains($script, 'cpu_percent'), "cpus=2\ncpu_percent=12.5\nmemory_total_mb=4096\nmemory_available_mb=1024\n");

    $metrics = resolve(ReadDatabaseMetrics::class)->handle($database)->keyBy('nodeId');

    expect($metrics[$up->id])->reachable->toBeTrue()
        ->and($metrics[$up->id]->values)->toMatchArray(['cpus' => '2', 'cpu_percent' => '12.5', 'threads_connected' => '3', 'questions' => '120', 'data_mb' => '40'])
        ->and($metrics[$down->id]->reachable)->toBeFalse();
});
