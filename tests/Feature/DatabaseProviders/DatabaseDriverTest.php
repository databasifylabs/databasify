<?php

declare(strict_types=1);

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DTOs\Databases\QuorumCount;
use App\Exceptions\Databases\MissingCapability;
use App\Models\Database;
use App\Models\ServerLog;

it('describes itself for the engine picker', function () {
    $engine = Database::providerFor('postgres');

    expect($engine->toArray())
        ->toMatchArray(['value' => 'postgres', 'label' => 'PostgreSQL', 'category' => $engine->category()])
        ->and($engine->toArray()['lines'])->not->toBeEmpty();
});

it('skips a step the engine has no script for without touching the server', function () {
    $database = idleDatabase('mariadb', 3);
    $ssh = fakeSsh();

    $database->provider()->nodes()->wake($database->nodes()->with('server')->first());

    expect($ssh->runs)->toBeEmpty()->and(ServerLog::count())->toBe(0);
});

it('runs a step as one named server log on its node', function () {
    $database = idleDatabase('postgres', 1);
    $node = $database->nodes()->with('server')->first();
    $ssh = fakeSsh()->respond();

    $database->provider()->nodes()->start($node, bootstrapsCluster: false);

    expect($ssh->runs)->toHaveCount(1)
        ->and($ssh->runs[0]['server'])->toBe($node->server->name)
        ->and(ServerLog::sole()->name)->toBe("{$node->server->name}: starting");
});

it('has no recovery position when the engine cannot report one', function () {
    $database = idleDatabase('mysql', 3);

    expect($database->provider()->cluster()->recoveryPosition($database->nodes()->with('server')->first()))->toBeNull();
});

it('counts a majority only above half of the members', function (int $total, int $online, bool $majority) {
    expect((new QuorumCount($total, $online))->hasMajority())->toBe($majority);
})->with([[3, 2, true], [4, 2, false], [0, 0, false]]);

it('knows which engines keep a point-in-time archive', function (string $engine, bool $archives) {
    expect(Database::providerFor($engine)->archive() !== null)->toBe($archives);
})->with([['postgres', true], ['mysql', true], ['mariadb', true], ['mongodb', true], ['valkey', false], ['redis', false]]);

it('knows which engines can migrate from an outside source', function () {
    expect(collect(Database::providers())->filter(fn (DatabaseDriver $engine): bool => $engine->migration() !== null)->keys()->sort()->values()->all())
        ->toBe(['mariadb', 'mongodb', 'mysql', 'postgres', 'redis', 'valkey']);
});

it('names the engine when a capability it lacks is asked for', function () {
    expect(MissingCapability::of(Database::providerFor('valkey'), 'archive')->getMessage())->toBe('Valkey databases cannot be recovered to a point in time.')
        ->and(MissingCapability::of(Database::providerFor('redis'), 'migration')->getMessage())->toBe('Redis databases cannot be migrated.');
});

it('allows two standbys on every engine', function (string $engine) {
    expect(Database::providerFor($engine)->maxStandbys())->toBe(2);
})->with(['postgres', 'mysql', 'mariadb', 'mongodb', 'valkey', 'redis']);

it('limits read-only nodes to what each engine can replicate to', function () {
    expect(idleDatabase('postgres', 1)->provider()->maxReadOnlyNodes())->toBe(16)
        ->and(idleDatabase('mongodb', 1, name: 'orders')->provider()->maxReadOnlyNodes())->toBe(47);
});

it('raises the PostgreSQL limit with its WAL sender and slot settings', function () {
    $database = idleDatabase('postgres', 1);
    $database->settings = $database->settings->withConfig(['max_wal_senders' => '40', 'max_replication_slots' => '30']);

    expect($database->provider()->maxReadOnlyNodes())->toBe(26);
});

it('limits MySQL and Valkey read-only nodes by the primary connection limit', function () {
    $mysql = idleDatabase('mysql', 1);
    $valkey = idleDatabase('valkey', 1, name: 'cache');

    expect($mysql->provider()->maxReadOnlyNodes())->toBe((int) $mysql->provider()->tunedSettings($mysql->primaryOrSeed()->resources())['max_connections'])
        ->and($valkey->provider()->maxReadOnlyNodes())->toBe((int) $valkey->provider()->tunedSettings($valkey->primaryOrSeed()->resources())['maxclients']);
});
