<?php

declare(strict_types=1);

use App\Enums\Databases\NodeRole;

beforeEach(function () {
    $this->scripts = fakeScripts();
    $this->node = engineNode();
});

it('reads the node role and version from the status script', function () {
    $this->scripts->respond('engines.postgres.nodes.status', ['role' => 'replica', 'version' => '18.6']);

    $status = $this->node->driver()->nodes()->status();

    expect($status->role)->toBe(NodeRole::Replica)->and($status->version)->toBe('18.6');
    $this->scripts->assertRan('engines.postgres.nodes.status', fn (string $script): bool => str_contains($script, 'unit_active databasify-patroni')
        && str_contains($script, 'http://127.0.0.1:8008/patroni')
        && str_contains($script, 'SELECT pg_is_in_recovery()'));
});

it('reads an offline node without a version', function () {
    $this->scripts->respond('engines.postgres.nodes.status', ['role' => 'offline', 'version' => null]);

    $status = $this->node->driver()->nodes()->status();

    expect($status->role)->toBe(NodeRole::Offline)->and($status->version)->toBeNull();
});

it('reads connections, queries and data size', function () {
    $this->scripts->respond('engines.postgres.nodes.metrics', ['connections' => 4, 'queries' => 1200, 'dataMb' => 38]);

    $metrics = $this->node->driver()->nodes()->metrics();

    expect($metrics)->connections->toBe(4)->queries->toBe(1200)->dataMb->toBe(38);
    $this->scripts->assertRan('engines.postgres.nodes.metrics', fn (string $script): bool => str_contains($script, "backend_type = 'client backend'"));
});
