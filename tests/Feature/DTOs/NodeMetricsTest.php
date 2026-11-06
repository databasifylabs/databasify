<?php

declare(strict_types=1);

use App\DTOs\Metrics\NodeMetrics;
use App\Enums\Databases\DatabaseMode;

it('parses metrics output for a node and marks unreachable nodes', function () {
    $node = idleDatabase('valkey', 1, DatabaseMode::Single)->seed();

    expect(NodeMetrics::fromOutput($node, "cpu_percent=12.5\nconnected_clients=4\n"))
        ->toEqual(new NodeMetrics($node->id, $node->server->name, true, ['cpu_percent' => '12.5', 'connected_clients' => '4']))
        ->and(NodeMetrics::unreachable($node))
        ->toEqual(new NodeMetrics($node->id, $node->server->name, false));
});
