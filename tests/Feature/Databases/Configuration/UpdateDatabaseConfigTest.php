<?php

declare(strict_types=1);

use App\Actions\Databases\Configuration\ApplyDatabaseConfig;
use App\Actions\Databases\Configuration\UpdateDatabaseConfig;
use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\AddDatabaseNodeJob;
use App\Jobs\Databases\ApplyDatabaseConfigJob;
use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

it('accepts only known engine settings and writes them into the engine config', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class, ApplyDatabaseConfigJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['requirepass' => 'x']])->assertSessionHasErrors('config');
    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['maxmemory-policy' => 'evict-all']])->assertSessionHasErrors('config.maxmemory-policy');
    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['maxmemory-policy' => 'allkeys-lru', 'timeout' => '']])->assertSessionHasNoErrors();

    $database->refresh();
    expect($database->settings->config)->toBe(['maxmemory-policy' => 'allkeys-lru'])
        ->and(renderedFile($database, 'valkey.conf'))->toContain("maxmemory-policy allkeys-lru\n");
    Queue::assertPushed(ApplyDatabaseConfigJob::class, fn (ApplyDatabaseConfigJob $job): bool => $job->keys === ['maxmemory-policy']);
    Queue::assertNotPushed(UpgradeDatabaseJob::class);
});

it('rejects engine settings that would crash mysql', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class, ApplyDatabaseConfigJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['default_time_zone' => '+25:00']])->assertSessionHasErrors('config.default_time_zone');
    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['collation_server' => 'klingon_ci']])->assertSessionHasErrors('config.collation_server');
    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['sql_mode' => 'STRICT_TRANS_TABLES,MADE_UP']])->assertSessionHasErrors('config.sql_mode');
    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['sql_mode' => 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', 'default_time_zone' => '+06:00']])->assertSessionHasNoErrors();
});

it('describes each engine setting with its default and choices', function () {
    Queue::fake([BootstrapDatabaseJob::class, AddDatabaseNodeJob::class]);
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');

    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.config_fields.0.key', 'maxmemory-policy')
        ->where('database.data.config_fields.0.label', 'Eviction policy')
        ->where('database.data.config_fields.0.default', 'noeviction')
        ->where('database.data.config_fields.0.options', fn ($options) => collect($options)->contains('allkeys-lru'))
        ->where('database.data.config_fields.0.multiple', false));
});

it('restarts every node in turn when a changed setting needs a restart', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withConfig(['work_mem' => '8MB', 'max_connections' => '300'])]);
    Queue::fake([UpgradeDatabaseJob::class, ApplyDatabaseConfigJob::class]);

    resolve(UpdateDatabaseConfig::class)->handle($database, ['statement_timeout' => '5000']);

    expect($database->fresh()->settings->config)->toBe(['statement_timeout' => '5000'])
        ->and($database->fresh()->operation)->toBe('Apply settings');
    Queue::assertPushed(UpgradeDatabaseJob::class, fn (UpgradeDatabaseJob $job): bool => $job->database->is($database) && ! $job->installUpdate);
});

it('writes settings into each engine its own config file', function (string $engine, array $config, string $file, array $lines) {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class, ApplyDatabaseConfigJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => $config])->assertRedirect()->assertSessionHasNoErrors();

    $database->refresh();
    expect($database->settings->config)->toBe($config)
        ->and(renderedFile($database, $file))->toContain(...$lines);
})->with([
    'postgres' => ['postgres', ['work_mem' => '16MB', 'default_transaction_isolation' => 'serializable'], 'postgresql.conf', ["work_mem = '16MB'\n", "default_transaction_isolation = 'serializable'\n"]],
    'mariadb' => ['mariadb', ['max_connections' => '500', 'slow_query_log' => 'ON'], '99-databasify.cnf', ["max_connections = 500\n", "slow_query_log = ON\n"]],
    'redis' => ['redis', ['maxclients' => '200'], 'redis.conf', ["maxclients 200\n"]],
]);

it('rejects config that is missing or out of pattern', function (string $engine, array $input, string $field) {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class, ApplyDatabaseConfigJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/config"), $input)->assertSessionHasErrors($field);

    expect($database->fresh()->settings->config)->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'missing config' => ['postgres', [], 'config'],
    'postgres memory unit' => ['postgres', ['config' => ['work_mem' => '16TB']], 'config.work_mem'],
    'postgres isolation' => ['postgres', ['config' => ['default_transaction_isolation' => 'chaos']], 'config.default_transaction_isolation'],
    'mariadb setting of mysql' => ['mariadb', ['config' => ['innodb_redo_log_capacity' => '1G']], 'config'],
    'mariadb slow log' => ['mariadb', ['config' => ['slow_query_log' => 'MAYBE']], 'config.slow_query_log'],
    'redis keyspace events' => ['redis', ['config' => ['notify-keyspace-events' => 'Q;']], 'config.notify-keyspace-events'],
]);

it('keeps the config while the database is busy', function () {
    $database = newDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class, ApplyDatabaseConfigJob::class]);

    expect(fn () => resolve(UpdateDatabaseConfig::class)->handle($database, ['timeout' => '30']))->toThrow(ValidationException::class, 'busy with Create Valkey database')
        ->and($database->fresh()->settings->config)->toBe([]);
    Queue::assertNothingPushed();
});

it('rejects a number outside the engine range with the allowed range', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class, ApplyDatabaseConfigJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['connect_timeout' => '1']])
        ->assertSessionHasErrors(['config.connect_timeout' => 'Connect timeout must be between 2 and 3600.']);

    expect($database->fresh()->settings->config)->toBe([]);
    Queue::assertNothingPushed();
});

it('applies settings that change at runtime without a restart', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withConfig(['wait_timeout' => '600', 'slow_query_log' => 'ON'])]);
    Queue::fake([UpgradeDatabaseJob::class, ApplyDatabaseConfigJob::class]);

    resolve(UpdateDatabaseConfig::class)->handle($database, ['wait_timeout' => '900', 'long_query_time' => '2']);

    expect($database->fresh()->operation)->toBe('Apply settings');
    Queue::assertPushed(ApplyDatabaseConfigJob::class, fn (ApplyDatabaseConfigJob $job): bool => $job->database->is($database) && $job->keys === ['wait_timeout', 'long_query_time', 'slow_query_log']);
    Queue::assertNotPushed(UpgradeDatabaseJob::class);
});

it('sets changed values live and resets removed ones to their defaults', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withConfig(['wait_timeout' => '900', 'innodb_buffer_pool_size' => '2G'])]);
    $database->startOperation('Apply settings');
    $ssh = fakeSsh();

    runJob(new ApplyDatabaseConfigJob($database->fresh(), ['wait_timeout', 'innodb_buffer_pool_size', 'slow_query_log', 'optimizer_switch']));

    expect($ssh->runs)->toHaveCount(2)
        ->and(fileWrittenIn($ssh->scriptAt(0), '/srv/mysql/conf/my.cnf'))->toContain("wait_timeout = 900\n")
        ->and($ssh->scriptAt(1))->toContain('msql "SET GLOBAL wait_timeout = 900"')
        ->toContain('msql "SET GLOBAL innodb_buffer_pool_size = 2147483648"')
        ->toContain("msql \"SET GLOBAL slow_query_log = 'OFF'\"")
        ->toContain('msql "SET GLOBAL optimizer_switch = DEFAULT"')
        ->and($database->fresh()->operation)->toBeNull();
});

it('applies valkey settings with config set and stops at a rejected value', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withConfig(['pubsub-output-buffer-limit' => '64', 'timeout' => '300'])]);
    $ssh = fakeSsh();

    resolve(ApplyDatabaseConfig::class)->handle($database->fresh(), ['pubsub-output-buffer-limit', 'timeout', 'notify-keyspace-events']);

    expect($ssh->scriptAt(1))->toContain("apply client-output-buffer-limit 'pubsub 64mb 16mb 60'")
        ->toContain("apply timeout '300'")
        ->toContain("apply notify-keyspace-events ''")
        ->toContain('[ "$result" = \'OK\' ] || { echo "could not set $1: $result"; exit 1; }');
});
