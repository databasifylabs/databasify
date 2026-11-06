<?php

declare(strict_types=1);

use App\Actions\Databases\Pools\ApplyConnectionPools;
use App\Enums\Databases\PoolMode;
use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use App\Models\DatabaseNode;
use App\Models\ServerLog;

it('writes every pool onto every node and reloads pgbouncer', function () {
    $database = idleDatabase('postgres', 3);
    $database->connectionPools()->create(['name' => 'web', 'schema' => 'app', 'mode' => PoolMode::Session, 'size' => 30]);
    $database->connectionPools()->create(['name' => 'reports', 'schema' => 'app', 'mode' => PoolMode::Transaction, 'size' => 10]);
    $database->startOperation('Add connection pool web');
    $ssh = fakeSsh();

    runJob(new ApplyConnectionPoolsJob($database));

    expect(collect($ssh->runs)->pluck('server')->all())->toBe($database->nodes()->with('server')->get()->map(fn (DatabaseNode $node): string => $node->server->name)->all())
        ->and(collect($ssh->runs)->pluck('script'))->each->toContain('databasify ctl reload databasify-pgbouncer')
        ->and(fileWrittenIn($ssh->scriptAt(0), '/srv/postgres/conf/patroni.yml'))->toBe('')
        ->and(fileWrittenIn($ssh->scriptAt(0), '/etc/systemd/system/databasify-pgbouncer.service'))->toBe('')
        ->and(fileWrittenIn($ssh->scriptAt(0), '/srv/postgres/conf/pgbouncer-users.txt'))->toContain('"pgbouncer"')
        ->and(fileWrittenIn($ssh->scriptAt(0), '/srv/postgres/conf/pgbouncer.ini'))->toContain("reports = host=127.0.0.1 port=25060 dbname=app pool_size=10 pool_mode=transaction\nweb = host=127.0.0.1 port=25060 dbname=app pool_size=30 pool_mode=session\n")
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->get())->toHaveCount(3)
        ->each(fn ($log) => $log->status->toBe(ServerLogStatus::Succeeded)->name->toEndWith(': updating connection pools'));
});

it('clears the pools when none are left', function () {
    $database = idleDatabase('postgres', 1);
    $ssh = fakeSsh();

    resolve(ApplyConnectionPools::class)->handle($database);

    expect(fileWrittenIn($ssh->scriptAt(0), '/srv/postgres/conf/pgbouncer.ini'))->toContain("[databases]\npostgres = host=127.0.0.1 port=25060 dbname=postgres pool_size=2\n\n[pgbouncer]");
});

it('fails the log of the node that fails after every node has run', function () {
    $database = idleDatabase('postgres', 3);
    $database->startOperation('Add connection pool web');
    $ssh = fakeSsh()->respond('Job for databasify-pgbouncer.service failed', 1);

    expect(fn () => runJob(new ApplyConnectionPoolsJob($database)))->toThrow(RuntimeException::class)
        ->and($ssh->runs)->toHaveCount(3)
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->where('status', ServerLogStatus::Failed)->count())->toBe(1);
});

it('finishes the operation and its running log when the queued job dies', function () {
    $database = idleDatabase('postgres', 1);
    $database->startOperation('Add connection pool web');
    $log = ServerLog::factory()->create(['server_id' => $database->seed()->server_id, 'database_id' => $database->id, 'status' => ServerLogStatus::Running, 'output' => '']);

    (new ApplyConnectionPoolsJob($database))->failed(new RuntimeException('worker timed out'));

    expect($database->fresh()->operation)->toBeNull()
        ->and($log->fresh())->status->toBe(ServerLogStatus::Failed)->output->toBe("▸ worker timed out\n");
});
