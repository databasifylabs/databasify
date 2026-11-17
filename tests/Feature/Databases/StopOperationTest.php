<?php

declare(strict_types=1);

use App\Engines\Data\Operation;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\OperationState;
use App\Exceptions\Engines\EngineError;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\User;
use App\Services\Databases\EngineTasks;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

function stopDatabase(Database $database): TestResponse
{
    return test()->actingAs(User::factory()->create())->post(route('databases.stop', [$database->project_id, $database]));
}

function stopNode(DatabaseNode $node): TestResponse
{
    return test()->actingAs(User::factory()->create())->post(route('databases.nodes.stop', [$node->database->project_id, $node->database, $node]));
}

test('a cancelled engine task fails the operation as stopped by you', function () {
    $database = Database::factory()->status(DatabaseStatus::Scaling)->create();
    $node = DatabaseNode::factory()->for($database)->create();
    $this->ssh()->replyTo('databasify task status', '{"key":"copy","kind":"copy","state":"cancelled","phase":"stopped"}');

    expect(fn () => app(EngineTasks::class)->await($node, 'run', 'copy', 'copying the data', fn (string $key): Operation => new Operation($key, 'copy', OperationState::Running, 'starting', null, null)))
        ->toThrow(EngineError::class, 'Stopped by you.');
});

test('stopping a database cancels its running engine tasks', function () {
    $database = Database::factory()->status(DatabaseStatus::Scaling)->create();
    $node = DatabaseNode::factory()->for($database)->create(['task_key' => 'run.node.copy']);
    $this->ssh()->replyTo('databasify task stop', '{"key":"run.node.copy","kind":"copy","state":"cancelled","phase":"stopped"}');

    stopDatabase($database)->assertSessionHasNoErrors();

    expect($this->ssh()->ran('databasify task stop'))->toBeTrue()
        ->and($node->refresh()->task_key)->toBeNull();
});

test('stopping a database leaves a read-only node its own task', function () {
    $database = Database::factory()->status(DatabaseStatus::Scaling)->create();
    DatabaseNode::factory()->for($database)->create(['task_key' => 'run.node.copy']);
    $readOnly = DatabaseNode::factory()->for($database)->readOnly()->create(['status' => DatabaseStatus::Updating, 'task_key' => 'run.ro.config']);
    $this->ssh()->replyTo('databasify task stop', '{"key":"run.node.copy","kind":"copy","state":"cancelled","phase":"stopped"}');

    stopDatabase($database)->assertSessionHasNoErrors();

    expect($readOnly->refresh()->task_key)->toBe('run.ro.config');
});

test('a read-only node stops its own engine task', function () {
    $database = Database::factory()->create();
    $readOnly = DatabaseNode::factory()->for($database)->readOnly()->create(['status' => DatabaseStatus::Updating, 'task_key' => 'run.ro.config']);
    $this->ssh()->replyTo('databasify task stop', '{"key":"run.node.copy","kind":"copy","state":"cancelled","phase":"stopped"}');

    stopNode($readOnly)->assertSessionHasNoErrors();

    expect($readOnly->refresh()->task_key)->toBeNull();
});

test('a read-only node in the database operation is stopped from the database', function () {
    $database = Database::factory()->status(DatabaseStatus::Scaling)->create(['name' => 'orders']);
    $readOnly = DatabaseNode::factory()->for($database)->readOnly()->create(['status' => DatabaseStatus::Scaling, 'task_key' => 'run.ro.copy']);
    $label = $readOnly->operationLabel();

    stopNode($readOnly)->assertSessionHasErrors(['database' => "{$label} is part of orders's operation, stop it from orders."]);

    expect($readOnly->refresh()->task_key)->toBe('run.ro.copy');
});

test('nothing is stopped without a running engine task', function () {
    $database = Database::factory()->status(DatabaseStatus::Scaling)->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();

    stopDatabase($database)->assertSessionHasErrors(['database' => 'orders has no running engine task to stop.']);
});

test('blocked operations cannot be stopped', function (DatabaseStatus $status, string $message) {
    $database = Database::factory()->status($status)->create(['name' => 'orders']);
    $node = DatabaseNode::factory()->for($database)->create(['task_key' => 'run.node.step']);

    stopDatabase($database)->assertSessionHasErrors(['database' => $message]);

    expect($node->refresh()->task_key)->toBe('run.node.step');
})->with([
    'recovering' => [DatabaseStatus::Recovering, 'orders is failing over or recovering, which cannot be stopped halfway.'],
    'migrating' => [DatabaseStatus::Migrating, "orders is migrating, use the migration's Stop or Finish instead."],
    'deleting' => [DatabaseStatus::Deleting, 'orders is being deleted, which cannot be stopped.'],
]);

test('the database page says when an engine task can be stopped', function () {
    $database = Database::factory()->status(DatabaseStatus::Scaling)->create();
    $node = DatabaseNode::factory()->for($database)->create(['task_key' => 'run.node.copy']);
    $this->actingAs(User::factory()->create());

    $this->get(route('databases.show', [$database->project_id, $database]))
        ->assertInertia(fn (Assert $page) => $page->where('database.data.running_task', true));

    $node->update(['task_key' => null]);

    $this->get(route('databases.show', [$database->project_id, $database]))
        ->assertInertia(fn (Assert $page) => $page->where('database.data.running_task', false));
});

test('a stop that cannot reach the engine says so and keeps the task', function () {
    $database = Database::factory()->status(DatabaseStatus::Scaling)->create(['name' => 'orders']);
    $node = DatabaseNode::factory()->for($database)->create(['task_key' => 'run.node.copy']);
    $this->ssh()->failOn('databasify task stop', new RuntimeException('connection refused'));

    stopDatabase($database)->assertSessionHasErrors(['database' => 'Could not stop the running task: connection refused']);

    expect($node->refresh()->task_key)->toBe('run.node.copy');
});
