<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Exceptions\Databases\ServerSizeUnknown;
use App\Models\DatabaseNode;

it('builds the same request on every node except ip', function () {
    $database = idleDatabase('postgres', 3);
    $database->update(['settings' => $database->settings->withConfig(['max_connections' => '200', 'work_mem' => ''])]);
    $nodes = $database->nodes()->with('server')->orderBy('position')->get();

    $payloads = $nodes->map(fn (DatabaseNode $node): array => resolve(BuildNodeConfig::class)->handle($database->fresh(), $node)->toPayload());

    expect($payloads->map(fn (array $payload): array => array_diff_key($payload, ['ip' => true]))->unique()->count())->toBe(1)
        ->and($payloads->pluck('ip')->all())->toBe($nodes->map(fn (DatabaseNode $node): string => $node->privateIp())->all())
        ->and($payloads[0])
        ->clustered->toBeTrue()
        ->readOnly->toBeFalse()
        ->databaseId->toBe($database->id)
        ->primary->toBe($nodes[0]->privateIp())
        ->settings->toEqual((object) ['max_connections' => '200'])
        ->secrets->toBe(['admin' => $database->settings->secret('ENGINE_ADMIN'), 'replication' => $database->settings->secret('ENGINE_REPLICATION'), 'agent' => $database->settings->secret('ENGINE_AGENT')])
        ->archive->toBeNull()
        ->and($payloads[0]['members'][1])->toBe(['ip' => $nodes[1]->privateIp(), 'position' => 1, 'voting' => true, 'cpus' => $nodes[1]->server->cpus, 'memoryMb' => $nodes[1]->server->memory_mb, 'diskGb' => $nodes[1]->server->disk_gb]);
});

it('marks read-only nodes as non-voting', function () {
    $database = idleDatabase('postgres', 1);
    $readOnly = withReadOnlyNode($database);

    $payload = resolve(BuildNodeConfig::class)->handle($database->fresh(), $readOnly->fresh())->toPayload();

    expect($payload)->readOnly->toBeTrue()->clustered->toBeTrue()
        ->and(array_column($payload['members'], 'voting'))->toBe([true, false]);
});

it('fails clearly when a server has no size', function () {
    $ssh = fakeSsh();
    $database = idleDatabase('postgres', 2);
    $node = $database->nodes()->orderBy('position')->get()[1];
    $node->server->update(['memory_mb' => null]);
    $before = count($ssh->runs);

    expect(fn () => resolve(BuildNodeConfig::class)->handle($database->fresh(), $database->nodes()->first()))
        ->toThrow(ServerSizeUnknown::class, "{$node->server->name} has no known CPU, memory or disk size yet.")
        ->and($ssh->runs)->toHaveCount($before);
});
