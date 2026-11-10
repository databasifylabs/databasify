<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\PoolMode;
use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('adds connection pools for known databases and applies them on every node', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    Queue::fake([ApplyConnectionPoolsJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/pools"), ['name' => 'app_pool', 'schema' => 'missing', 'mode' => 'transaction', 'size' => 20])->assertSessionHasErrors('schema');
    $this->post(projectUrl("/databases/{$database->id}/pools"), ['name' => 'app_pool', 'schema' => 'defaultdb', 'mode' => 'transaction', 'size' => 20])->assertSessionHasNoErrors();
    $pool = $database->connectionPools()->sole();
    $ssh = fakeEngine();

    runJob(new ApplyConnectionPoolsJob($database->fresh()));

    expect($ssh->scriptAt(1))->toContain('| databasify pool create ')
        ->and(json_decode($ssh->engineRequestAt(1), true))->toBe(['name' => 'app_pool', 'database' => 'defaultdb', 'size' => 20, 'mode' => 'transaction']);
    Queue::assertPushed(ApplyConnectionPoolsJob::class);

    $this->delete(projectUrl("/databases/{$database->id}/pools/{$pool->id}"))->assertSessionHasNoErrors();
    expect($database->connectionPools()->count())->toBe(0);
});

it('applies a new pool on the nodes straight away when the queue runs it', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $ssh = fakeEngine();

    $this->post(projectUrl("/databases/{$database->id}/pools"), ['name' => 'reports', 'schema' => 'defaultdb', 'mode' => 'session', 'size' => 5])->assertRedirect()->assertSessionHasNoErrors();

    expect(json_decode($ssh->engineRequestAt(1), true))->toBe(['name' => 'reports', 'database' => 'defaultdb', 'size' => 5, 'mode' => 'session'])
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->sole())
        ->name->toBe("{$database->seed()->server->name}: updating connection pools")
        ->status->toBe(ServerLogStatus::Succeeded);
});

it('rejects pools with invalid input', function (array $input, string $field) {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->connectionPools()->create(['name' => 'taken', 'schema' => 'defaultdb', 'mode' => PoolMode::Transaction, 'size' => 10]);
    idleDatabase('postgres', 1, DatabaseMode::Single, 'other')->schemas()->create(['name' => 'foreign']);
    Queue::fake([ApplyConnectionPoolsJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/pools"), [...['name' => 'reports', 'schema' => 'defaultdb', 'mode' => 'transaction', 'size' => 20], ...$input])->assertSessionHasErrors($field);

    expect($database->connectionPools()->pluck('name')->all())->toBe(['taken']);
    Queue::assertNothingPushed();
})->with([
    'missing name' => [['name' => ''], 'name'],
    'uppercase name' => [['name' => 'Reports'], 'name'],
    'leading digit' => [['name' => '1reports'], 'name'],
    'too long name' => [['name' => str_repeat('a', 64)], 'name'],
    'reserved pgbouncer' => [['name' => 'pgbouncer'], 'name'],
    'reserved postgres' => [['name' => 'postgres'], 'name'],
    'taken name' => [['name' => 'taken'], 'name'],
    'database of another database' => [['schema' => 'foreign'], 'schema'],
    'unknown mode' => [['mode' => 'pipelined'], 'mode'],
    'zero size' => [['size' => 0], 'size'],
    'oversized' => [['size' => 501], 'size'],
    'non numeric size' => [['size' => 'big'], 'size'],
]);

it('refuses pools on engines without pgbouncer over http', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    Queue::fake([ApplyConnectionPoolsJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/pools"), ['name' => 'reports', 'schema' => 'defaultdb', 'mode' => 'transaction', 'size' => 20])->assertSessionHasErrors('name');

    expect($database->connectionPools()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['mysql', 'mariadb']);

it('only deletes pools of the database in the url', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $foreign = idleDatabase('postgres', 1, DatabaseMode::Single, 'other')->connectionPools()->create(['name' => 'reports', 'schema' => 'defaultdb', 'mode' => PoolMode::Transaction, 'size' => 10]);
    Queue::fake([ApplyConnectionPoolsJob::class]);

    $this->delete(projectUrl("/databases/{$database->id}/pools/{$foreign->id}"))->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
    Queue::assertNothingPushed();
});
