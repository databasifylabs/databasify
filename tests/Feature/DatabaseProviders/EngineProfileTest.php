<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Models\Database;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('describes how to connect and migrate from the engine itself', function () {
    $mysql = idleDatabase('mysql', 1, DatabaseMode::Single, 'db');
    $valkey = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');

    $this->get(projectUrl("/databases/{$mysql->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.connection.uri_scheme', 'mysql')
        ->where('database.data.connection.fixed_username', null)
        ->where('database.data.connection.env_keys.database', 'DB_DATABASE')
        ->where('database.data.migration_source', ['default_port' => 3306, 'credentials_required' => true, 'selects_databases' => true])
        ->where('database.data.supports_users', true));

    $this->get(projectUrl("/databases/{$valkey->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.connection.uri_scheme', 'rediss')
        ->where('database.data.connection.fixed_username', 'default')
        ->where('database.data.migration_source.default_port', 6379)
        ->where('database.data.supports_users', false)
        ->where('database.data.failover_hint', fn (string $hint) => str_contains($hint, 'Sentinel')));
});

it('offers application engines before caching engines with their version lines', function () {
    $this->get(projectUrl('/databases/create'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('engines', fn ($engines): bool => collect($engines)->map(fn (array $engine): string => "{$engine['category']}:{$engine['value']}")->all() === [
            'Application:mysql', 'Application:postgres', 'Application:mariadb', 'Application:mongodb', 'Caching:valkey', 'Caching:redis',
        ])
        ->where('engines.4', ['value' => 'valkey', 'label' => 'Valkey', 'icon' => Database::providerFor('valkey')->icon(), 'category' => 'Caching', 'max_standbys' => 2, 'lines' => Database::providerFor('valkey')->versions()->map(fn ($line): array => $line->toArray())->all()]));
});

it('addresses members on the engine port', function () {
    $mysql = idleDatabase('mysql', 1, DatabaseMode::Single, 'db');
    $valkey = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');

    expect(Database::providerFor('mysql')->clientAddress($mysql->seed()))->toBe($mysql->seed()->privateIp().':25060')
        ->and(Database::providerFor('valkey')->clientAddress($valkey->seed()))->toBe($valkey->seed()->privateIp().':6379');
});

it('runs redis on the sentinel topology with its own names', function () {
    $database = idleDatabase('redis', 3, name: 'sessions');
    $reader = withReadOnlyNode($database);
    $config = renderedFile($database, 'redis.conf', $reader);

    expect($database->provider()->directory())->toBe('/srv/redis')
        ->and($database->provider()->processes()->names())->toBe(['databasify-redis', 'databasify-redis-sentinel'])
        ->and($database->settings->secret('REDIS_PASSWORD'))->toHaveLength(32)
        ->and($config)->toContain("replica-priority 0\n")->toContain("appendonly yes\n")->toContain("replicaof {$database->seed()->privateIp()} 6379\n")
        ->and(renderedFile($database, 'sentinel.conf'))->toContain("sentinel monitor main {$database->seed()->privateIp()} 6379 3\n")
        ->and($database->provider()->environment($database->endpoints()))->toMatchArray(['REDIS_PASSWORD' => $database->settings->secret('REDIS_PASSWORD')])
        ->and(view('ssh.engines.redis.helpers', $database->provider()->scriptData()->for())->render())->toContain('/usr/local/bin/redis-cli -h 127.0.0.1 -p 6379')->toContain('$(cat /srv/redis/conf/password)')
        ->and(view('ssh.engines.redis.lifecycle.status', $database->provider()->scriptData()->for())->render())->toContain('/^redis_version:/');
});
