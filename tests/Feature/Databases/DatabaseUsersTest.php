<?php

declare(strict_types=1);

use App\DTOs\Databases\EngineImage;
use App\Engines\Data\Grant;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\PasswordEncryption;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\User;
use Tests\Support\EngineRecording;
use Tests\Support\RecordingDriver;

function databaseWithDefaultAccount(array $attributes = []): Database
{
    $database = Database::factory()->create(['name' => 'orders', ...$attributes]);
    DatabaseNode::factory()->for($database)->create();
    $database->recordDefaultAccounts();

    return $database;
}

function engine(): EngineRecording
{
    return app(EngineRecording::class);
}

test('a database user is created with write access to every database', function () {
    RecordingDriver::fake();
    $database = databaseWithDefaultAccount();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.users.store', [$database->project_id, $database]), ['name' => 'reporting'])
        ->assertSessionHasNoErrors();

    $user = $database->users()->where('name', 'reporting')->sole();
    [$name, $password, $grants] = engine()->callsOf('accounts.createUser')->sole()['args'];
    expect($name)->toBe('reporting')
        ->and($password)->toBe($user->password)
        ->and($grants)->toEqual([new Grant('databasify', GrantAccess::Write)]);
});

test('reserved and taken user names are refused', function (string $name, string $message) {
    RecordingDriver::fake();
    $database = databaseWithDefaultAccount();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.users.store', [$database->project_id, $database]), ['name' => $name])
        ->assertSessionHasErrors(['name' => $message]);

    expect(engine()->called('accounts.createUser'))->toBeFalse();
})->with([
    'reserved' => ['postgres', 'The selected name is invalid.'],
    'taken' => ['databasify', 'The name has already been taken.'],
    'not an identifier' => ['drop table;', 'The name field format is invalid.'],
]);

test('a user password can be reset', function () {
    RecordingDriver::fake();
    $database = databaseWithDefaultAccount();
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'old-password']);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.users.password', [$database->project_id, $database, $user]))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->password)->not->toBe('old-password')
        ->and(engine()->callsOf('accounts.setPassword')->sole()['args'][1])->toBe($user->password);
});

test('a mysql user can switch password encryption', function () {
    RecordingDriver::fake();
    $database = databaseWithDefaultAccount(['engine' => 'mysql', 'version_line' => '8.4']);
    $user = $database->users()->create(['name' => 'legacy', 'password' => 'secret', 'password_encryption' => 'caching_sha2_password']);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.users.encryption', [$database->project_id, $database, $user]), ['password_encryption' => 'mysql_native_password'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->password_encryption)->toBe(PasswordEncryption::MysqlNativePassword)
        ->and(engine()->callsOf('accounts.setPassword')->sole()['args'])->toBe(['legacy', 'secret', PasswordEncryption::MysqlNativePassword]);
});

test('password encryptions the engine does not offer are refused', function () {
    RecordingDriver::fake();
    $database = databaseWithDefaultAccount();
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'secret']);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.users.encryption', [$database->project_id, $database, $user]), ['password_encryption' => 'md5'])
        ->assertSessionHasErrors(['password_encryption' => 'The selected password encryption is invalid.']);

    expect(engine()->changedNothing())->toBeTrue();
});

test('a user can be deleted but the default user cannot', function () {
    RecordingDriver::fake();
    $database = databaseWithDefaultAccount();
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'secret']);
    $default = $database->users()->where('name', 'databasify')->sole();
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->delete(route('databases.users.destroy', [$database->project_id, $database, $user]))
        ->assertSessionHasNoErrors();
    $this->actingAs($owner)
        ->delete(route('databases.users.destroy', [$database->project_id, $database, $default]))
        ->assertSessionHasErrors(['user' => 'databasify is the default user and can\'t be deleted.']);

    $this->assertModelMissing($user);
    $this->assertModelExists($default);
    expect(engine()->callsOf('accounts.dropUser')->pluck('args')->all())->toBe([['reporting']]);
});

test('a user of another database cannot be touched through this one', function () {
    RecordingDriver::fake();
    $database = databaseWithDefaultAccount();
    $foreign = databaseWithDefaultAccount(['name' => 'shop'])->users()->sole();

    $this->actingAs(User::factory()->create())
        ->delete(route('databases.users.destroy', [$database->project_id, $database, $foreign]))
        ->assertNotFound();

    $this->assertModelExists($foreign);
});

test('accounts are not changed while the image runs an unsupported sdk', function () {
    RecordingDriver::fake();
    $database = databaseWithDefaultAccount(['image' => new EngineImage('ghcr.io/databasifylabs/engine-postgres', 'sha256:'.str_repeat('c', 64), '18.0', '0.1.0')]);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.users.store', [$database->project_id, $database]), ['name' => 'reporting'])
        ->assertSessionHasErrors('database');

    expect(engine()->changedNothing())->toBeTrue();
});
