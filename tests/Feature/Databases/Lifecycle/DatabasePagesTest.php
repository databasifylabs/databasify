<?php

declare(strict_types=1);

use App\DTOs\Migrations\MigrationSource;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\AddDatabaseNodeJob;
use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Models\Database;
use App\Models\Server;
use App\Models\ServerLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([BootstrapDatabaseJob::class, AddDatabaseNodeJob::class]);
    $this->actingAs(User::factory()->create());
});

it('shows the laravel settings and adds a server', function () {
    $ids = Server::factory()->ready()->count(1)->create()->pluck('id')->all();
    $this->post(projectUrl('/databases'), ['name' => 'cache', 'engine' => 'valkey', 'primary_server_id' => $ids[0]]);
    $database = Database::query()->sole();
    $database->finishOperation();
    $extra = Server::factory()->ready()->create();

    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('databases/show')
        ->where('appEnv.REDIS_PORT', '25061')
        ->where('availableServers.data.0.id', $extra->id));

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $extra->id])->assertRedirect();
    Queue::assertPushed(AddDatabaseNodeJob::class);
});

it('lists every database with its nodes', function () {
    idleDatabase('mysql', 3);
    idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');

    $this->get(projectUrl('/databases'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('databases/index')
        ->where('databases.data.0.name', 'cache')
        ->has('databases.data.0.nodes', 1)
        ->where('databases.data.1.name', 'main')
        ->has('databases.data.1.nodes', 3));
});

it('shows a database with its connection settings and newest server logs first', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $server = $database->seed()->server;
    ServerLog::factory()->for($server)->create(['database_id' => $database->id, 'name' => 'Older', 'created_at' => now()->subMinute()]);
    $newer = ServerLog::factory()->for($server)->create(['database_id' => $database->id, 'name' => 'Newer']);
    ServerLog::factory()->for($server)->create(['name' => 'Server only', 'created_at' => now()->addMinute()]);

    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('databases/show')
        ->where('database.data.name', 'main')
        ->where('appEnv.DB_PORT', '25060')
        ->where('clientIp', '127.0.0.1')
        ->has('logs.data', 2)
        ->where('logs.data.0.name', 'Newer')
        ->where('logs.data.1.name', 'Older')
        ->where('log.name', 'Newer')
        ->where('latestLogId', $newer->id));

    $this->get(projectUrl('/databases/999'))->assertNotFound();
});

it('shows the operation a database is busy with in the list', function () {
    newDatabase('valkey', 2);
    $idle = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');

    $this->get(projectUrl('/databases'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('databases/index')
        ->where('databases.data.0.id', $idle->id)
        ->where('databases.data.0.operation', null)
        ->where('databases.data.1.operation', 'Create Valkey database'));
});

it('marks the node that becomes primary while the database is created', function () {
    $database = newDatabase('valkey', 2);

    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.status', 'creating')
        ->where('database.data.nodes.0.is_seed', true)
        ->where('database.data.nodes.1.is_seed', false));
});

it('offers the database own ca certificate for download, with or without a domain', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, 'db');
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'private-ca-pem'])]);

    expect(($database->fresh() ?? $database)->clientCaCertificate())->toBe('private-ca-pem')
        ->and(withZone($database)->clientCaCertificate())->toBe('private-ca-pem');
});

it('searches databases by name or engine and filters them by engine and status', function (string $query, array $names) {
    idleDatabase('postgres', 1, DatabaseMode::Single, 'analytics')->update(['status' => DatabaseStatus::Ready]);
    idleDatabase('mysql', 1, DatabaseMode::Single, 'orders')->update(['status' => DatabaseStatus::Ready]);
    idleDatabase('valkey', 1, DatabaseMode::Single, 'cache')->update(['status' => DatabaseStatus::Failed]);

    $this->get(projectUrl("/databases?{$query}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('databases.data', fn ($databases) => collect($databases)->pluck('name')->sort()->values()->all() === $names));
})->with([
    'by name' => ['search=ord', ['orders']],
    'by engine label' => ['search=postgres', ['analytics']],
    'by engine' => ['engine=valkey', ['cache']],
    'by status' => ['status=ready', ['analytics', 'orders']],
    'engine and status together' => ['engine=mysql&status=failed', []],
]);

it('offers every engine and status as a database filter', function () {
    $this->get(projectUrl('/databases?engine=redis'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filters', ['search' => '', 'status' => null, 'engine' => 'redis'])
        ->has('filterOptions.engines', count(Database::engines()))
        ->where('filterOptions.engines.0', ['value' => 'valkey', 'label' => 'Valkey'])
        ->where('filterOptions.statuses', array_map(fn (DatabaseStatus $status) => $status->value, DatabaseStatus::cases())));
});

it('lists databases without per-node relations', function () {
    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(projectUrl('/databases'))->assertOk();

        return count(DB::getQueryLog());
    };

    $queriesFor();
    idleDatabase('valkey', 2, DatabaseMode::Single, 'first');
    $withOne = $queriesFor();
    foreach (range(1, 4) as $number) {
        idleDatabase('valkey', 2, DatabaseMode::Single, "extra-{$number}");
    }

    expect($queriesFor())->toBe($withOne);
});

it('keeps the migration history off the list and on the database page', function () {
    $main = idleDatabase('mysql', 3);
    foreach (range(1, 6) as $number) {
        $main->migrations()->create(['mode' => MigrationMode::OneTime, 'status' => MigrationStatus::Completed, 'source' => new MigrationSource("db{$number}.example.com", 3306, 'migrator', 'secret')]);
    }

    $this->get(projectUrl('/databases'))->assertInertia(fn (AssertableInertia $page) => $page
        ->missing('databases.data.0.migrations'));
    $this->get(projectUrl("/databases/{$main->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('database.data.migrations', 5)
        ->where('database.data.migrations.0.source', 'db6.example.com:3306')
        ->where('database.data.migrations.4.source', 'db2.example.com:3306'));
});
