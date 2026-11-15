<?php

declare(strict_types=1);

use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\User;
use Tests\Support\RecordingDriver;

function postgresWithDefaults(): Database
{
    $database = Database::factory()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();
    $database->recordDefaultAccounts();

    return $database;
}

test('a schema is created and every user gets access to it', function () {
    $engine = RecordingDriver::fake();
    $database = postgresWithDefaults();
    $database->users()->create(['name' => 'reporting', 'password' => 'secret']);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.schemas.store', [$database->project_id, $database]), ['name' => 'analytics'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('schemas', ['database_id' => $database->id, 'name' => 'analytics']);
    expect($engine->callsOf('accounts.createDatabase')->sole()['args'])->toBe(['analytics'])
        ->and($engine->callsOf('accounts.grant')->map(fn (array $call) => $call['args'][0])->all())->toBe(['databasify', 'reporting']);
});

test('reserved schema names are refused', function () {
    $engine = RecordingDriver::fake();
    $database = postgresWithDefaults();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.schemas.store', [$database->project_id, $database]), ['name' => 'template1'])
        ->assertSessionHasErrors(['name' => 'The selected name is invalid.']);

    expect($engine->changedNothing())->toBeTrue();
});

test('a schema can be deleted but the default schema cannot', function () {
    $engine = RecordingDriver::fake();
    $database = postgresWithDefaults();
    $schema = $database->schemas()->create(['name' => 'analytics']);
    $default = $database->schemas()->where('name', 'databasify')->sole();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('databases.schemas.destroy', [$database->project_id, $database, $schema]))
        ->assertSessionHasNoErrors();
    $this->actingAs($user)
        ->delete(route('databases.schemas.destroy', [$database->project_id, $database, $default]))
        ->assertSessionHasErrors(['schema' => 'databasify is the default database and can\'t be deleted.']);

    $this->assertModelMissing($schema);
    $this->assertModelExists($default);
    expect($engine->callsOf('accounts.dropDatabase')->pluck('args')->all())->toBe([['analytics']]);
});

test('key value engines have no schemas to manage', function () {
    $engine = RecordingDriver::fake();
    $database = Database::factory()->engine('valkey', '9')->create();
    DatabaseNode::factory()->for($database)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.schemas.store', [$database->project_id, $database]), ['name' => 'analytics'])
        ->assertSessionHasErrors(['database' => 'Valkey databases have no users or databases to manage.']);

    expect($engine->changedNothing())->toBeTrue();
});
