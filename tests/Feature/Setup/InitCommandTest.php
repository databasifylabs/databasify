<?php

declare(strict_types=1);

use App\Actions\Setup\ReleaseInterruptedJobs;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Enums\Servers\ServerLogStatus;
use App\Enums\Servers\ServerStatus;
use App\Models\Server;
use Illuminate\Support\Facades\Redis;

it('ends interrupted database work', function () {
    $creating = idleDatabase('postgres', 1, name: 'creating');
    $creating->update(['status' => DatabaseStatus::Creating]);
    $upgrading = idleDatabase('postgres', 1, name: 'upgrading');
    $upgrading->update(['status' => DatabaseStatus::Upgrading]);
    $ready = idleDatabase('postgres', 1, name: 'ready');
    $failed = idleDatabase('postgres', 1, name: 'failed');
    $failed->update(['status' => DatabaseStatus::Failed]);
    $upgrading->requestStop();

    $this->artisan('databasify:init')->assertSuccessful();

    expect($creating->fresh()?->status)->toBe(DatabaseStatus::Failed)
        ->and($upgrading->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($ready->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($failed->fresh()?->status)->toBe(DatabaseStatus::Failed)
        ->and($upgrading->isStopRequested())->toBeFalse();
});

it('fails servers that were setting up', function () {
    $provisioning = Server::factory()->ready()->create(['status' => ServerStatus::Provisioning]);
    $ready = Server::factory()->ready()->create();

    $this->artisan('databasify:init')->assertSuccessful();

    expect($provisioning->fresh()?->status)->toBe(ServerStatus::Failed)
        ->and($ready->fresh()?->status)->toBe(ServerStatus::Ready);
});

it('fails running server logs with the restart note', function () {
    $database = idleDatabase('postgres', 1);
    $log = $database->seed()->openLog('db-1: upgrade');

    $this->artisan('databasify:init')->assertSuccessful();

    expect($log->fresh())->status->toBe(ServerLogStatus::Failed)
        ->output->toContain('Interrupted by an app restart.');
});

it('leaves data migrations alone', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);

    $this->artisan('databasify:init')->assertSuccessful();

    expect($migration->fresh()?->status)->toBe(MigrationStatus::Syncing);
});

it('keeps starting when a step throws', function () {
    $database = idleDatabase('postgres', 1);
    $database->update(['status' => DatabaseStatus::Upgrading]);
    config()->set('queue.default', 'redis');
    config()->set('database.redis.default.port', 1);

    $this->artisan('databasify:init')->assertSuccessful();

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('removes reserved jobs and keeps pending ones', function () {
    config()->set('queue.default', 'redis');
    config()->set('database.redis.options.prefix', 'databasify-init-test:');
    $redis = Redis::connection();
    $redis->rpush('queues:databases', '{"uuid":"pending","displayName":"Pending","job":"x","data":[]}');
    $redis->zadd('queues:databases:reserved', time() + 60, '{"uuid":"reserved","id":"reserved","displayName":"Reserved","job":"x","data":[],"attempts":1}');

    try {
        expect(resolve(ReleaseInterruptedJobs::class)->handle())->toBe(1)
            ->and($redis->zcard('queues:databases:reserved'))->toBe(0)
            ->and($redis->llen('queues:databases'))->toBe(1);
    } finally {
        $redis->del('queues:databases', 'queues:databases:reserved', 'reserved');
    }
})->skip(fn (): bool => rescue(fn () => Redis::connection()->ping() === false, true, report: false), 'Redis is not running.');
