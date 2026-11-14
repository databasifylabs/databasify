<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('creates and deletes databases over http', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    idleDatabase('postgres', 1, DatabaseMode::Single, 'other')->schemas()->create(['name' => 'analytics']);
    fakeEngine();

    $this->post(projectUrl("/databases/{$database->id}/schemas"), ['name' => 'analytics'])->assertRedirect()->assertSessionHasNoErrors();
    $schema = $database->schemas()->where('name', 'analytics')->sole();
    $this->delete(projectUrl("/databases/{$database->id}/schemas/{$schema->id}"))->assertRedirect()->assertSessionHasNoErrors();

    expect(recordedCalls())->toContain('accounts.createDatabase')
        ->and(recordedArgs('accounts.dropDatabase')[0]['args'])->toBe(['analytics'])
        ->and($database->schemas()->pluck('name')->all())->toBe(['defaultdb']);
});

it('rejects database names that are invalid, reserved or taken', function (string $engine, mixed $name) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $ssh = fakeEngine();

    $this->post(projectUrl("/databases/{$database->id}/schemas"), ['name' => $name])->assertSessionHasErrors('name');

    expect($ssh->runs)->toBe([])
        ->and($database->schemas()->pluck('name')->all())->toBe(['defaultdb']);
})->with([
    'missing' => ['mysql', ''],
    'leading underscore' => ['mysql', '_analytics'],
    'space' => ['mysql', 'my db'],
    'too long' => ['mysql', str_repeat('a', 65)],
    'taken' => ['mysql', 'defaultdb'],
    'mysql reserved' => ['mysql', 'sys'],
    'mariadb reserved' => ['mariadb', 'databasify'],
    'postgres reserved' => ['postgres', 'template1'],
]);

it('refuses databases on sentinel databases over http', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);

    $this->post(projectUrl("/databases/{$database->id}/schemas"), ['name' => 'analytics'])->assertSessionHasErrors('database');

    expect($database->schemas()->count())->toBe(0);
})->with(['valkey', 'redis']);

it('only deletes databases of the database in the url', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $foreign = idleDatabase('mysql', 1, DatabaseMode::Single, 'other')->schemas()->sole();
    $ssh = fakeEngine();

    $this->delete(projectUrl("/databases/{$database->id}/schemas/{$foreign->id}"))->assertNotFound();

    expect($ssh->runs)->toBe([])
        ->and($foreign->fresh())->not->toBeNull();
});

it('refuses to delete the default database over http', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $schema = $database->schemas()->sole();
    $ssh = fakeEngine();

    $this->delete(projectUrl("/databases/{$database->id}/schemas/{$schema->id}"))->assertSessionHasErrors(['schema' => "defaultdb is the default database and can't be deleted."]);

    expect($ssh->runs)->toBe([])
        ->and($database->schemas()->pluck('name')->all())->toBe(['defaultdb']);
});
